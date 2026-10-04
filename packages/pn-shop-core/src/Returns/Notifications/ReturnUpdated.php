<?php

namespace PnShop\Returns\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use PnShop\Returns\Models\ReturnRequest;
use PnShop\Returns\ReturnStatus;
use PnShop\Sales\Notifications\OrderMail;

/**
 * Tells the customer where their return request stands.
 */
class ReturnUpdated extends OrderMail
{
    public function __construct(public ReturnRequest $return)
    {
        parent::__construct($return->order()->firstOrFail());
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number = $this->return->number;

        $message = $this->message()->subject(match ($this->return->status) {
            ReturnStatus::Requested => __('We received your return request :number', ['number' => $number]),
            default => __('Your return :number: :status', ['number' => $number, 'status' => __($this->return->status->label())]),
        });

        $message->line(match ($this->return->status) {
            ReturnStatus::Requested => __('We will review your request for order :order and get back to you.', ['order' => $this->order->number]),
            ReturnStatus::Approved => __('Your return is approved. Please send the items back with the return number :number on the parcel.', ['number' => $number]),
            ReturnStatus::Rejected => __('We cannot accept this return.'),
            ReturnStatus::Received => __('The items you sent back have arrived.'),
            ReturnStatus::Refunded => __('Your return has been refunded.'),
            ReturnStatus::Exchanged => __('We have sent your exchange as order :order.', ['order' => (string) $this->return->exchangeOrder?->number]),
            ReturnStatus::Closed => __('Your return is closed.'),
        });

        if ($this->return->staff_note !== null && in_array($this->return->status, [ReturnStatus::Approved, ReturnStatus::Rejected, ReturnStatus::Closed], true)) {
            $message->line($this->return->staff_note);
        }

        return $this->orderButton($message);
    }
}
