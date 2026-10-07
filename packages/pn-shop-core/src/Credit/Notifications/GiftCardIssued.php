<?php

namespace PnShop\Credit\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use PnShop\Credit\Models\GiftCard;
use PnShop\Sales\Notifications\RetriesDelivery;
use PnShop\Sales\Notifications\StoreMailIdentity;
use PnShop\Settings\Settings;

/**
 * The gift card's code for its recipient. The code exists only in this email (and the
 * notice staff saw once): it is not stored.
 */
class GiftCardIssued extends Notification implements ShouldQueue
{
    use Queueable, RetriesDelivery;

    /**
     * @param  string|null  $from  the buyer, for a gift card bought in the shop
     * @param  string|null  $recipientName  how the buyer named the recipient
     */
    public function __construct(public GiftCard $card, public string $code, public ?string $from = null, public ?string $recipientName = null)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        StoreMailIdentity::apply();
        $store = (string) app(Settings::class)->get('store.name');

        $message = (new MailMessage)->subject(__('Your :store gift card', ['store' => $store]));

        if ($this->recipientName !== null && $this->recipientName !== '') {
            $message->greeting(__('Hello :name,', ['name' => $this->recipientName]));
        }

        if ($this->from !== null && $this->from !== '') {
            $message->line(__(':name sent you a gift card.', ['name' => $this->from]));
        }

        if ($this->card->message !== null && $this->card->message !== '') {
            $message->line('"'.$this->card->message.'"');
        }

        $message
            ->line(__('You have a gift card for :amount.', ['amount' => $this->card->balanceMoney()->formatToLocale(app()->getLocale())]))
            ->line(__('Your code: :code', ['code' => $this->code]));

        if ($this->card->expires_at !== null) {
            $message->line(__('Use it before :date.', ['date' => $this->card->expires_at->isoFormat('LL')]));
        }

        return $message
            ->line(__('Enter the code in your cart or at checkout.'))
            ->action(__('Visit the shop'), url('/'))
            ->salutation(__('Thank you, :store', ['store' => $store]));
    }
}
