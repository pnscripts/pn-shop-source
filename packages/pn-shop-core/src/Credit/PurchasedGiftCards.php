<?php

namespace PnShop\Credit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PnShop\Credit\Models\GiftCard;
use PnShop\Credit\Notifications\GiftCardIssued;
use PnShop\Payment\Models\Refund;
use PnShop\Sales\Models\Order;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\FulfillmentStatus;

/**
 * Gift cards sold as products. When the order is paid, each gift card unit becomes a card of
 * its price, emailed to its recipient (the buyer when none was given); the units count as
 * delivered. Refunding them cancels the cards.
 */
final class PurchasedGiftCards
{
    public function __construct(private Balances $balances, private OrderWorkflow $workflow) {}

    /** Issue the order's gift cards not issued yet (applied once per unit). */
    public function issue(Order $order): void
    {
        $issued = DB::transaction(function () use ($order): int {
            $count = 0;

            foreach ($order->items()->lockForUpdate()->get() as $item) {
                if (! $item->isGiftCard()) {
                    continue;
                }

                $done = GiftCard::query()->where('order_item_id', $item->id)->count();
                $due = $item->quantity - $item->quantity_cancelled - $done;

                for ($index = $done; $index < $done + $due; $index++) {
                    $recipient = (array) ($item->gift_card_recipients[$index] ?? []);
                    $email = is_string($recipient['email'] ?? null) && $recipient['email'] !== '' ? $recipient['email'] : $order->email;

                    [$card, $code] = $this->balances->issueGiftCard($item->unitPrice(), recipientEmail: $email, note: __('Bought in order :number', ['number' => $order->number]), order: $order);
                    $card->forceFill(['order_item_id' => $item->id, 'message' => is_string($recipient['message'] ?? null) ? $recipient['message'] : null])->save();

                    Notification::route('mail', $email)->notify(new GiftCardIssued($card, $code, from: $order->name, recipientName: is_string($recipient['name'] ?? null) ? $recipient['name'] : null));
                    $count++;
                }

                if ($due > 0) {
                    $item->forceFill(['quantity_fulfilled' => $item->quantity_fulfilled + $due])->save();
                }
            }

            return $count;
        });

        if ($issued === 0) {
            return;
        }

        $order->refresh();
        $this->workflow->addNote($order, trans_choice('{1} Issued 1 gift card and emailed it.|[2,*] Issued :count gift cards and emailed them.', $issued, ['count' => $issued]));

        // Nothing else to ship: the order is delivered.
        if ($order->items()->get()->every(fn ($item) => $item->quantityToShip() === 0) && $order->fulfillment_status->canTransitionTo(FulfillmentStatus::Fulfilled)) {
            $this->workflow->transition($order, FulfillmentStatus::Fulfilled);
        }
    }

    /**
     * Refunded gift card units cancel cards of their line (the newest first). A card already
     * partly used is cancelled too; the note tells staff what was left on it.
     */
    public function refunded(Refund $refund): void
    {
        $notes = [];

        foreach ($refund->lines()->with('item')->get() as $line) {
            $item = $line->item;

            if ($item === null || ! $item->isGiftCard()) {
                continue;
            }

            $cards = GiftCard::query()->where('order_item_id', $item->id)->where('is_active', true)->orderByDesc('id')->limit($line->quantity)->get();

            foreach ($cards as $card) {
                if ($card->balance < $card->initial_amount) {
                    $notes[] = __('Gift card :card had been used: :left of :amount was left when it was cancelled.', [
                        'card' => $card->label(),
                        'left' => $card->balanceMoney()->formatToLocale(app()->getLocale()),
                        'amount' => $item->unitPrice()->formatToLocale(app()->getLocale()),
                    ]);
                }

                if ($card->balance > 0) {
                    $this->balances->change($card, $card->balanceMoney()->negated(), BalanceReason::Adjustment, $refund->order, note: __('Refunded'));
                }

                $card->forceFill(['is_active' => false])->save();
            }
        }

        if ($notes !== [] && $refund->order !== null) {
            $this->workflow->addNote($refund->order, implode(' ', $notes));
        }
    }
}
