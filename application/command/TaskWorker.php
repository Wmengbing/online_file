<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Db;
use think\facade\Env;

class TaskWorker extends Command
{
    protected function configure()
    {
        $this->setName('task:worker')
            ->setDescription('异步任务执行器(清理回收站/临时分片/生成统计)');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->info('任务工作器启动...');

        while (true) {
            $task = Db::name('async_tasks')
                ->where('status', 0)
                ->where(function ($query) {
                    $query->where('scheduled_at', null)
                        ->whereOr('scheduled_at', '<=', date('Y-m-d H:i:s'));
                })
                ->order('id', 'asc')
                ->find();

            if (!$task) {
                sleep(5);
                continue;
            }

            Db::name('async_tasks')
                ->where('id', $task['id'])
                ->update([
                    'status'     => 1,
                    'started_at' => date('Y-m-d H:i:s'),
                ]);

            try {
                $result = $this->executeTask($task);

                Db::name('async_tasks')
                    ->where('id', $task['id'])
                    ->update([
                        'status'       => 2,
                        'result'       => json_encode($result, JSON_UNESCAPED_UNICODE),
                        'completed_at' => date('Y-m-d H:i:s'),
                    ]);

                $output->info("任务 {$task['id']} 执行成功");
            } catch (\Exception $e) {
                $retry_count = $task['retry_count'] + 1;
                $status = $retry_count >= $task['max_retries'] ? 3 : 0;

                Db::name('async_tasks')
                    ->where('id', $task['id'])
                    ->update([
                        'status'        => $status,
                        'retry_count'   => $retry_count,
                        'error_message' => $e->getMessage(),
                        'completed_at'  => $status == 3 ? date('Y-m-d H:i:s') : null,
                    ]);

                $output->error("任务 {$task['id']} 执行失败: " . $e->getMessage());
            }
        }
    }

    private function executeTask($task)
    {
        $payload = json_decode($task['payload'], true) ?: [];

        switch ($task['type']) {
            case 'clean_recycle':
                return $this->cleanRecycle($payload);

            case 'clean_temp':
                return $this->cleanTemp($payload);

            case 'generate_stats':
                return $this->generateStats($payload);

            default:
                throw new \Exception('未知任务类型: ' . $task['type']);
        }
    }

    /**
     * 清理已过期回收站记录(含目录整棵子树,物理文件引用计数安全)
     */
    private function cleanRecycle($payload)
    {
        $expire_time = date('Y-m-d H:i:s', strtotime('-' . ((int)($payload['days'] ?? 30)) . ' days'));

        $expired = Db::name('file_recycle')
            ->alias('r')
            ->join('files f', 'f.id = r.file_id', 'LEFT')
            ->where('r.expire_time', '<=', $expire_time)
            ->where('f.id', 'not null')
            ->field('r.user_id, r.file_id, r.id as recycle_id')
            ->select();

        if (empty($expired)) {
            return ['deleted' => 0];
        }

        // 按用户分组,每用户一批执行(自动合并重叠子树)
        $by_user = [];
        foreach ($expired as $row) {
            $by_user[(int)$row['user_id']][] = (int)$row['file_id'];
            // 同时清除过期行自身(子树内的行可能尚未过期,但随根删除会一并被助手清除)
        }

        $total = 0;
        foreach ($by_user as $user_id => $root_ids) {
            $total += hard_delete_file_tree($user_id, $root_ids) > 0 ? 1 : 0;
        }

        // 兜底:硬删后仍残留的过期记录(指向已不存在文件等)直接清理
        Db::name('file_recycle')->where('expire_time', '<=', $expire_time)->delete();

        return ['deleted_trees' => count($by_user), 'users' => count($by_user)];
    }

    /**
     * 清理超期未完成的分片(数据库记录 + 物理文件)
     */
    private function cleanTemp($payload)
    {
        $days = (int)($payload['days'] ?? 7);
        $expire_time = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $chunks = Db::name('file_chunks')
            ->where('created_at', '<', $expire_time)
            ->where('status', 0)
            ->select();

        $deleted_count = 0;
        $paths = [];
        foreach ($chunks as $chunk) {
            if ($chunk['chunk_path']) {
                $paths[$chunk['chunk_path']] = 1;
            }
            $deleted_count++;
        }

        foreach (array_keys($paths) as $path) {
            $full_path = get_file_full_path($path);
            if (file_exists($full_path)) {
                @unlink($full_path);
            }
        }

        Db::name('file_chunks')
            ->where('created_at', '<', $expire_time)
            ->where('status', 0)
            ->delete();

        // 清理遗留的空分片目录
        $tmp_root = Env::get('root_path') . 'uploads' . DIRECTORY_SEPARATOR . 'tmp';
        if (is_dir($tmp_root)) {
            foreach (glob($tmp_root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
                $entries = @scandir($dir);
                if ($entries !== false && count($entries) <= 2) {
                    @rmdir($dir);
                }
            }
        }

        return ['deleted_chunks' => $deleted_count];
    }

    /**
     * 生成用户每日存储统计(重复执行时先清当日旧数据)
     */
    private function generateStats($payload)
    {
        $stat_date = $payload['date'] ?? date('Y-m-d');

        Db::name('storage_stats')->where('stat_date', $stat_date)->delete();

        $users = Db::name('users')
            ->where('deleted_at', null)
            ->column('id');

        $count = 0;
        foreach ($users as $user_id) {
            $total_files = Db::name('files')
                ->where('user_id', $user_id)
                ->where('type', 1)
                ->where('status', 1)
                ->count();

            $total_size = (int)Db::name('files')
                ->where('user_id', $user_id)
                ->where('type', 1)
                ->where('status', 1)
                ->sum('size');

            $file_type_stats = Db::name('files')
                ->where('user_id', $user_id)
                ->where('type', 1)
                ->where('status', 1)
                ->field('extension, COUNT(*) as count, SUM(size) as total_size')
                ->group('extension')
                ->select();

            Db::name('storage_stats')->insert([
                'user_id'         => $user_id,
                'total_files'     => $total_files,
                'total_size'      => $total_size,
                'file_type_stats' => json_encode($file_type_stats, JSON_UNESCAPED_UNICODE),
                'stat_date'       => $stat_date,
            ]);
            $count++;
        }

        return ['user_count' => $count, 'stat_date' => $stat_date];
    }
}
