<?php

namespace PnShop\Credit\Filament\Resources\GiftCards\Pages;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as Notifications;
use PnShop\Acl\Models\AdminUser;
use PnShop\Credit\Balances;
use PnShop\Credit\Filament\Resources\GiftCards\GiftCardResource;
use PnShop\Credit\Notifications\GiftCardIssued;

/**
 * Issues a card through Balances, shows its code once, and emails it to the recipient.
 */
class CreateGiftCard extends CreateRecord
{
    protected static string $resource = GiftCardResource::class;

    protected static ?string $title = 'Issue gift card';

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $admin = auth('admin')->user();

        [$card, $code] = app(Balances::class)->issueGiftCard(
            Money::of((string) $data['amount'], (string) $data['currency'], roundingMode: RoundingMode::HalfUp),
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            $data['recipient_email'] ?? null,
            $data['note'] ?? null,
            $admin instanceof AdminUser ? $admin : null,
        );

        if ($card->recipient_email !== null) {
            Notifications::route('mail', $card->recipient_email)->notify(new GiftCardIssued($card, $code));
        }

        Notification::make()
            ->success()
            ->persistent()
            ->title(__('Gift card code: :code', ['code' => $code]))
            ->body($card->recipient_email !== null
                ? __('Sent to :email. It is shown only now: copy it if you need it.', ['email' => $card->recipient_email])
                : __('It is shown only now: copy it and give it to the customer.'))
            ->send();

        return $card;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
