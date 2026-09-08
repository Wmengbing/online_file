<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Db;

/**
 * 异步任务执行器(常驻循环,供 supervisor/systemd 托管)。
 * 轮询 async_tasks 队列执行:清理回收站/临时分片/生成统计。
 * 若不想常驻,可用 task:schedule(cron 单次调度)兜底,见 TaskSchedule。
 * 注意:本目录文件会被 think 控制台整体 include,命令类之间不要互相继承,
 *       公共清理逻辑在 application/common.php(clean_expired_recycle 等)。
 */
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
                return clean_expired_recycle((int)($payload['days'] ?? 30));

            case 'clean_temp':
                return clean_stale_chunks((int)($payload['days'] ?? 7));

            case 'generate_stats':
                return generate_daily_stats($payload['date'] ?? null);

            default:
                throw new \Exception('未知任务类型: ' . $task['type']);
        }
    }
}
