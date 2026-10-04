<?php

namespace PnShop\Shipping\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderItem;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\StockAllocations;
use PnShop\Shipping\ShipmentService;

/**
 * Ship some or all of an order's remaining lines, with an optional tracking number, from
 * a stock location. With several locations, the form starts from the location holding
 * most of the order's units and fills in what is waiting there.
 */
class CreateShipmentAction
{
    public static function make(): Action
    {
        $remaining = fn (Order $record) => $record->items->filter(fn (OrderItem $item) => $item->quantityToShip() > 0);

        return Action::make('createShipment')
            ->label('Create shipment')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->authorize(fn (Order $record) => auth('admin')->user()?->can('update', $record) ?? false)
            ->visible(fn (Order $record) => $record->status !== OrderStatus::Cancelled && $remaining($record)->isNotEmpty())
            ->fillForm(function (Order $record) use ($remaining): array {
                $held = app(StockAllocations::class)->heldByLocation($record);
                $location = self::busiest($held);

                return [
                    'location' => $location,
                    'quantities' => $remaining($record)->mapWithKeys(fn (OrderItem $item) => [
                        $item->id => count($held) > 1 ? ($held[$location][$item->id] ?? 0) : $item->quantityToShip(),
                    ])->all(),
                ];
            })
            ->schema(fn (Order $record) => [
                Select::make('location')
                    ->label('Ships from')
                    ->options(fn () => StockLocation::query()->where('is_active', true)->ordered()->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->visible(fn () => StockLocation::query()->count() > 1)
                    ->afterStateUpdated(function (Set $set, mixed $state) use ($record, $remaining): void {
                        $held = app(StockAllocations::class)->heldByLocation($record)[(int) $state] ?? [];

                        foreach ($remaining($record) as $item) {
                            $set('quantities.'.$item->id, $held[$item->id] ?? 0);
                        }
                    })
                    ->helperText('Items waiting at another location are moved here if this location has them.'),
                Section::make('Items')
                    ->statePath('quantities')
                    ->schema($remaining($record)->map(fn (OrderItem $item) => TextInput::make((string) $item->id)
                        ->label(trim($item->product_title.($item->variant_label ? " ({$item->variant_label})" : '')))
                        ->helperText($item->quantityToShip().' of '.$item->quantity.' left to ship')
                        ->integer()
                        ->minValue(0)
                        ->maxValue($item->quantityToShip()))
                        ->values()
                        ->all()),
                TextInput::make('tracking_number')->maxLength(100),
                Textarea::make('note')->rows(2)->maxLength(1000),
            ])
            ->action(function (Order $record, array $data, Action $action): void {
                $quantities = array_filter(array_map('intval', $data['quantities'] ?? []));

                if ($quantities === []) {
                    Notification::make()->warning()->title('Choose at least one item to ship.')->send();
                    $action->halt();
                }

                try {
                    $location = isset($data['location']) ? StockLocation::query()->whereKey($data['location'])->first() : null;
                    app(ShipmentService::class)->ship($record, $quantities, $data['tracking_number'] ?? null, $data['note'] ?? null, auth('admin')->user(), $location);
                } catch (OrderException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }

                $record->refresh();

                Notification::make()->success()->title('Shipment created.')->send();
            });
    }

    /**
     * The location holding most of the order's waiting units (null when nothing is held).
     *
     * @param  array<int, array<int, int>>  $held
     */
    private static function busiest(array $held): ?int
    {
        $totals = array_map('array_sum', $held);
        arsort($totals);

        return array_key_first($totals);
    }
}
