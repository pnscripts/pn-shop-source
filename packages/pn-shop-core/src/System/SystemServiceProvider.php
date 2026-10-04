<?php

namespace PnShop\System;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\ModuleServiceProvider;
use PnShop\System\Policies\ActivityPolicy;
use Spatie\Activitylog\Models\Activity;

class SystemServiceProvider extends ModuleServiceProvider
{
    protected function permissions(): array
    {
        return [
            new Permission('system.activity.view', 'View the activity log', 'System'),
        ];
    }

    protected function bootModule(): void
    {
        Gate::policy(Activity::class, ActivityPolicy::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (self::queueRunsFromScheduler()) {
                $schedule->command('queue:work', ['--stop-when-empty', '--tries=3', '--max-time='.max(1, (int) config('pnshop.queue.max_time', 50))])
                    ->everyMinute()
                    // The lock expires after a few minutes, so a killed run cannot hold up the queue for long.
                    ->withoutOverlapping(5);
            }
        });
    }

    /**
     * Whether cron also works through the queue (pnshop.queue.work_from_scheduler), so emails go
     * out on hosts without a running worker. Not for the sync and null queues: nothing waits there.
     */
    private static function queueRunsFromScheduler(): bool
    {
        $driver = config('queue.connections.'.config('queue.default').'.driver');

        return (bool) config('pnshop.queue.work_from_scheduler', true) && ! in_array($driver, ['sync', 'null'], true);
    }
}
