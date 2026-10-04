<?php

namespace PnShop\Sales\Notifications;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * For queued store emails: a mail server that is down for a while must not lose the email.
 * Each attempt is retried after a growing pause (1, 5, 15 and 60 minutes); after the last
 * one the job stays in failed_jobs (`php artisan queue:failed`, `queue:retry`) and the
 * failure is logged.
 */
trait RetriesDelivery
{
    /** Attempts before the email is given up. */
    public int $tries = 5;

    /** Seconds one attempt may take, below the queue's retry_after (90) so a hung mail server is not sent to twice. */
    public int $timeout = 60;

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function failed(Throwable $e): void
    {
        $context = ['notification' => static::class, 'error' => $e->getMessage()];

        if (property_exists($this, 'order') && isset($this->order->number)) {
            $context['order'] = $this->order->number;
        }

        Log::error('A store email could not be sent and was given up; see `php artisan queue:failed`.', $context);
    }
}
