<?php

namespace PnShop\Shipping\Filament\RelationManagers;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\Models\Order;
use PnShop\Shipping\Models\Shipment;
use PnShop\Shipping\Models\ShipmentLine;
use PnShop\Shipping\Waybills;

class ShipmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'shipments';

    protected static ?string $title = 'Shipments';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return true;
    }

    private function order(): Order
    {
        $order = $this->getOwnerRecord();

        return $order instanceof Order ? $order : Order::query()->whereKey($order->getKey())->firstOrFail();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lines.item', 'location']))
            ->columns([
                TextColumn::make('shipped_at')->label('Shipped')->dateTime(),
                TextColumn::make('carrier_name')->label('Method')->placeholder('—'),
                TextColumn::make('location.name')->label('From')->placeholder('—'),
                TextColumn::make('items')
                    ->state(fn (Shipment $record) => $record->lines->map(fn (ShipmentLine $line) => $line->quantity.' × '.$line->item?->product_title)->all())
                    ->listWithLineBreaks(),
                TextColumn::make('tracking_number')->label('Tracking')->placeholder('—')
                    ->url(fn (Shipment $record) => $record->tracking_url, shouldOpenInNewTab: true),
                TextColumn::make('cash_on_delivery')->label('Collects')->placeholder('—')
                    ->state(fn (Shipment $record) => $record->cash_on_delivery === null ? null : Money::ofMinor($record->cash_on_delivery, $this->order()->currency)->formatToLocale(app()->getLocale())),
                TextColumn::make('note')->placeholder('—')->wrap(),
            ])
            ->recordActions([
                Action::make('downloadWaybill')
                    ->label('Waybill')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->visible(fn (Shipment $record) => $record->hasWaybill())
                    ->authorize(fn () => auth('admin')->user()?->can('view', $this->order()) ?? false)
                    ->action(fn (Shipment $record) => app(Waybills::class)->download($record)),
                Action::make('createWaybill')
                    ->label('Create waybill')
                    ->icon(Heroicon::OutlinedDocumentPlus)
                    ->modalDescription('Books this parcel with the carrier. Its number becomes the tracking number.')
                    ->fillForm(fn () => ['cash_on_delivery' => (string) (app(Waybills::class)->cashOnDelivery($this->order())?->getAmount() ?? '0')])
                    ->schema(function (): array {
                        $maximum = app(Waybills::class)->maximumCashOnDelivery($this->order());

                        return $maximum !== null && $maximum->isPositive() ? [
                            TextInput::make('cash_on_delivery')
                                ->label('Cash on delivery')
                                ->numeric()
                                ->minValue(0)
                                ->prefix($this->order()->currency)
                                ->helperText('What the courier collects with this parcel. At most '.$maximum->formatToLocale(app()->getLocale()).'.'),
                        ] : [];
                    })
                    ->visible(fn (Shipment $record) => app(Waybills::class)->canCreateFor($record))
                    ->authorize(fn () => auth('admin')->user()?->can('update', $this->order()) ?? false)
                    ->action(function (Shipment $record, array $data, Action $action): void {
                        $entered = trim((string) ($data['cash_on_delivery'] ?? ''));

                        try {
                            app(Waybills::class)->createFor($record, auth('admin')->user(), $entered === '' ? null : Money::of($entered, $this->order()->currency, roundingMode: RoundingMode::HalfUp), suggested: false);
                        } catch (OrderException $e) {
                            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                            $action->halt();
                        }

                        Notification::make()->success()->title(__('Waybill :number created.', ['number' => (string) $record->refresh()->tracking_number]))->send();
                    }),
            ]);
    }
}
