<?php

namespace Tests\Feature\Core;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PnShop\Credit\Notifications\GiftCardIssued;
use PnShop\Returns\Notifications\ReturnUpdated;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Notifications\NewOrderForStaff;
use PnShop\Sales\Notifications\OrderConfirmation;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Store emails go through the queue: they are retried when the mail server fails, end up in
 * failed_jobs when they cannot be sent, and get sent by cron on hosts without a worker.
 */
class QueuedEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_emails_are_retried_with_growing_pauses(): void
    {
        foreach ([OrderConfirmation::class, ReturnUpdated::class, NewOrderForStaff::class, GiftCardIssued::class] as $class) {
            $job = new SendQueuedNotifications(collect(), (new ReflectionClass($class))->newInstanceWithoutConstructor());

            $this->assertSame(5, $job->tries, $class);
            $this->assertSame([60, 300, 900, 3600], $job->backoff(), $class);
            $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout, $class);
        }
    }

    public function test_an_email_the_mail_server_refuses_is_retried_then_kept_in_failed_jobs(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'broken']]);
        Mail::extend('broken', fn () => throw new RuntimeException('Mail server down'));
        Log::spy();
        $order = Order::factory()->create();

        Notification::route('mail', 'shop@example.com')->notify(new NewOrderForStaff($order));
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
        $job = DB::table('jobs')->sole();
        $this->assertSame(1, (int) $job->attempts);
        $this->assertGreaterThanOrEqual(now()->addSeconds(59)->getTimestamp(), (int) $job->available_at);

        foreach ([300, 900, 3600] as $pause) {
            $this->travel($pause + 1)->seconds();
            $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
            $this->assertSame(1, DB::table('jobs')->count());
        }
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $this->travel(3601)->seconds();
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $failed = DB::table('failed_jobs')->sole();
        $this->assertStringContainsString('Mail server down', $failed->exception);
        $this->assertStringContainsString('NewOrderForStaff', $failed->payload);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'given up')
            && ($context['order'] ?? null) === $order->number)->once();
    }

    public function test_cron_works_through_the_queue_when_no_worker_runs(): void
    {
        config(['queue.default' => 'database']);

        $event = $this->queueEvent();
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('--stop-when-empty', $event->command);
        $this->assertStringContainsString('--max-time=50', $event->command);

        // What the scheduled command does: send what is waiting, then stop.
        Mail::fake();
        Notification::route('mail', 'shop@example.com')->notify(new NewOrderForStaff(Order::factory()->create()));
        // (--memory: the whole test suite runs in this process and would trip the worker's 128 MB default.)
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--max-time' => 50, '--memory' => 4096])->assertSuccessful();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_cron_leaves_the_queue_alone_when_turned_off_or_not_needed(): void
    {
        config(['queue.default' => 'database', 'pnshop.queue.work_from_scheduler' => false]);
        $this->assertNull($this->queueEvent());

        config(['queue.default' => 'sync', 'pnshop.queue.work_from_scheduler' => true]);
        $this->assertNull($this->queueEvent());
    }

    private function queueEvent(): ?Event
    {
        $this->app->forgetInstance(Schedule::class);

        return collect(app(Schedule::class)->events())->first(fn (Event $event) => str_contains((string) $event->command, 'queue:work'));
    }
}
