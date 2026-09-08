<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 每日定时清理调度(单次执行,供 cron 调用,不依赖常驻 worker)。
 * 默认:回收站过期 30 天自动清、残留分片 7 天自动清、生成昨日存储统计。
 * 用法:php think task:schedule   (建议每天凌晨执行一次,配合常驻 worker 双保险)
 */
class TaskSchedule extends Command
{
    protected function configure()
    {
        $this->setName('task:schedule')
            ->setDescription('每日清理调度:回收站过期/残留分片/存储统计(cron 用,单次执行后退出)');
    }

    protected function execute(Input $input, Output $output)
    {
        try {
            $recycle = clean_expired_recycle(30);
            $temp    = clean_stale_chunks(7);
            $stats   = generate_daily_stats(date('Y-m-d', strtotime('-1 day')));

            $output->info(sprintf(
                '清理完成:回收站 %s,分片 %s,统计 %s',
                json_encode($recycle, JSON_UNESCAPED_UNICODE),
                json_encode($temp, JSON_UNESCAPED_UNICODE),
                json_encode($stats, JSON_UNESCAPED_UNICODE)
            ));
        } catch (\Exception $e) {
            $output->error('清理失败: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
