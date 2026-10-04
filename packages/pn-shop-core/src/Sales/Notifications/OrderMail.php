<?php

namespace PnShop\Sales\Notifications;

use Brick\Money\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderItem;
use PnShop\Sales\OrderLinks;
use PnShop\Settings\Settings;

/**
 * Base for the emails customers get about an order: queued, in the order's language,
 * with the store's name and a signed link to the order page.
 */
abstract class OrderMail extends Notification implements ShouldQueue
{
    use Queueable, RetriesDelivery;

    public function __construct(public Order $order)
    {
        $this->afterCommit();
        $this->locale($order->locale);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    abstract public function toMail(object $notifiable): MailMessage;

    protected function message(): MailMessage
    {
        StoreMailIdentity::apply();

        return (new MailMessage)
            ->greeting(__('Hello :name,', ['name' => $this->order->name]))
            ->salutation(__('Thank you, :store', ['store' => (string) app(Settings::class)->get('store.name')]));
    }

    protected function orderButton(MailMessage $message): MailMessage
    {
        return $message->action(__('View your order'), OrderLinks::signedShow($this->order));
    }

    protected function money(Money $money): string
    {
        return $money->formatToLocale((string) ($this->order->locale ?? app()->getLocale()));
    }

    /**
     * One line per order line, e.g. "2 × Mug (Blue) — €12.00".
     */
    protected function itemLines(MailMessage $message): MailMessage
    {
        foreach ($this->order->items as $item) {
            /** @var OrderItem $item */
            $message->line($item->quantity.' × '.trim($item->product_title.($item->variant_label ? " ({$item->variant_label})" : '')).' — '.$this->money($item->lineTotal()));
        }

        return $message;
    }
}
