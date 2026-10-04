<?php

namespace PnShop\Payment\Filament\Actions;

use Brick\Money\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use PnShop\Payment\Models\Refund;
use PnShop\Payment\RefundService;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderItem;
use PnShop\Sales\States\PaymentStatus;

/**
 * Refund some lines and/or an extra amount (shipping, goodwill) through the payment's gateway.
 */
class RefundAction
{
    public static function make(): Action
    {
        $refundable = fn (Order $record) => $record->items->filter(fn (OrderItem $item) => $item->quantity_refunded < $item->quantity);

        return Action::make('refund')
            ->label('Refund')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('gray')
            ->authorize(fn (Order $record) => auth('admin')->user()?->can('update', $record) ?? false)
            ->visible(fn (Order $record) => in_array($record->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true))
            ->schema(fn (Order $record) => [
                Section::make('Items')
                    ->statePath('quantities')
                    ->visible($refundable($record)->isNotEmpty())
                    ->schema($refundable($record)->map(fn (OrderItem $item) => TextInput::make((string) $item->id)
                        ->label(trim($item->product_title.($item->variant_label ? " ({$item->variant_label})" : '')))
                        ->helperText(($item->quantity - $item->quantity_refunded).' refundable, '.$item->unitPrice()->formatToLocale(app()->getLocale()).' each')
                        ->integer()
                        ->minValue(0)
                        ->maxValue($item->quantity - $item->quantity_refunded)
                        ->default(0))
                        ->values()
                        ->all()),
                TextInput::make('extra')
                    ->label('Additional amount')
                    ->helperText('e.g. the shipping, or a goodwill refund.')
                    ->numeric()
                    ->minValue(0)
                    ->prefix($record->currency),
                Toggle::make('restock')->label('Put returned items back in stock')->default(true)
                    ->helperText('Items that had not shipped are always released.'),
                Textarea::make('reason')->rows(2)->maxLength(1000),
                Select::make('to')
                    ->label('Refund to')
                    ->options([Refund::TO_ORIGINAL => 'The order\'s payment', Refund::TO_STORE_CREDIT => 'Store credit (a gift card for guests)'])
                    ->default(Refund::TO_ORIGINAL)
                    ->required(),
            ])
            ->modalSubmitActionLabel('Refund')
            ->action(function (Order $record, array $data, Action $action): void {
                $extra = filled($data['extra'] ?? null) ? Money::of((string) $data['extra'], $record->currency) : null;

                try {
                    $refund = app(RefundService::class)->refund(
                        $record,
                        array_filter(array_map('intval', $data['quantities'] ?? [])),
                        $extra,
                        (bool) ($data['restock'] ?? false),
                        $data['reason'] ?? null,
                        auth('admin')->user(),
                        (string) ($data['to'] ?? Refund::TO_ORIGINAL),
                    );
                } catch (OrderException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                $record->refresh();

                Notification::make()->success()->title('Refunded '.$refund->amount->formatToLocale(app()->getLocale()).'.')->send();
            });
    }
}
