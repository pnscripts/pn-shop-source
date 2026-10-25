<?php

namespace PnShop\Plugins\Withdrawal;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PnShop\Channel\Channels;
use PnShop\Plugins\Withdrawal\Models\Withdrawal;
use PnShop\Plugins\Withdrawal\Notifications\WithdrawalForStaff;
use PnShop\Plugins\Withdrawal\Notifications\WithdrawalReceived;
use PnShop\Sales\Models\Order;
use PnShop\Settings\Settings;

/**
 * Accepts withdrawal statements and confirms them.
 *
 * Every statement is accepted, also when it matches no order or looks late: the law makes
 * the statement count when it is sent in time, and staff judge the rest. It is linked to
 * the order when the order number and email match one.
 */
final class WithdrawalStatements
{
    public function __construct(private Settings $settings) {}

    /**
     * @param  array{name: string, email: string, order_number: string, items?: string|null}  $statement
     */
    public function submit(array $statement): Withdrawal
    {
        $order = $this->matchingOrder($statement['order_number'], $statement['email']);

        $withdrawal = DB::transaction(function () use ($statement, $order) {
            $withdrawal = new Withdrawal;
            $withdrawal->forceFill([
                'reference' => Withdrawal::newReference(),
                'order_id' => $order?->id,
                'channel_id' => app(Channels::class)->current()->id,
                'order_number' => trim($statement['order_number']),
                'name' => trim($statement['name']),
                'email' => trim($statement['email']),
                'items' => filled($statement['items'] ?? null) ? trim((string) $statement['items']) : null,
                'locale' => app()->getLocale(),
                'submitted_at' => Carbon::now(),
            ])->save();

            return $withdrawal;
        });

        // Confirmation on a durable medium (email), sent right away, and the staff copy.
        Notification::route('mail', $withdrawal->email)->notify(new WithdrawalReceived($withdrawal));

        if (($staff = $this->staffEmail()) !== '') {
            Notification::route('mail', $staff)->notify(new WithdrawalForStaff($withdrawal));
        }

        return $withdrawal;
    }

    /** The order the statement is about: same number and the order's email. */
    public function matchingOrder(string $number, string $email): ?Order
    {
        $order = Order::query()->where('number', trim($number))->first();

        return $order !== null && mb_strtolower(trim((string) $order->email)) === mb_strtolower(trim($email)) ? $order : null;
    }

    /**
     * Whether the statement came after the withdrawal period, counted from the last
     * shipment (or the order date when nothing was shipped). Null when no order matched.
     */
    public function looksLate(Withdrawal $withdrawal): ?bool
    {
        $order = $withdrawal->order;

        if ($order === null) {
            return null;
        }

        $shipped = $order->shipments()->max('shipped_at');
        $from = $shipped !== null ? Carbon::parse($shipped) : $order->created_at;
        $days = max(1, (int) ($this->settings->get('plugin.pnshop_withdrawal.period_days') ?? 14));

        return $from !== null && $withdrawal->submitted_at->greaterThan($from->copy()->addDays($days)->endOfDay());
    }

    private function staffEmail(): string
    {
        return trim((string) ($this->settings->get('plugin.pnshop_withdrawal.notify_email')
            ?: $this->settings->get('notifications.staff_email')
            ?: $this->settings->get('store.email')));
    }
}
