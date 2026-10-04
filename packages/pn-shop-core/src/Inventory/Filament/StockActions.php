<?php

namespace PnShop\Inventory\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use PnShop\Acl\Models\AdminUser;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Inventory\Exceptions\InsufficientStock;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\Models\StockLocation;

/**
 * Admin actions for a variant's stock at each location: count it, or move it between
 * locations. Both are recorded in the stock history. Shown once there are several locations.
 */
final class StockActions
{
    /**
     * @param  Closure(mixed): ?ProductVariant  $variant  the variant of the action's record
     */
    public static function byLocation(Closure $variant, string $name = 'stockByLocation'): Action
    {
        return Action::make($name)
            ->label('Stock by location')
            ->icon(Heroicon::OutlinedBuildingStorefront)
            ->visible(fn (mixed $record) => self::severalLocations() && $variant($record)?->track_inventory)
            ->authorize(fn () => self::canManage())
            ->modalDescription('Units on the shelf at each location, reserved ones included. Changes are recorded in the stock history.')
            ->fillForm(function (mixed $record) use ($variant): array {
                $subject = $variant($record);
                $inventory = app(InventoryService::class);

                return StockLocation::query()->ordered()->get()
                    ->mapWithKeys(fn (StockLocation $location) => ["location_{$location->id}" => $subject === null ? 0 : $inventory->onHandAt($subject, $location)])
                    ->all();
            })
            ->schema(fn (mixed $record) => [
                ...StockLocation::query()->ordered()->get()->map(function (StockLocation $location) use ($variant, $record) {
                    $subject = $variant($record);
                    $reserved = $subject === null ? 0 : app(InventoryService::class)->reservedAt($subject, $location);

                    return TextInput::make("location_{$location->id}")
                        ->label($location->name.($location->sells_online && $location->is_active ? '' : ' ('.__('not sold online').')'))
                        ->integer()
                        ->minValue(0)
                        ->required()
                        ->helperText($reserved > 0 ? (string) __(':count reserved for open orders.', ['count' => $reserved]) : null);
                })->all(),
                TextInput::make('note')->maxLength(500),
            ])
            ->action(function (array $data, mixed $record) use ($variant): void {
                $subject = $variant($record);

                if ($subject === null) {
                    return;
                }

                foreach (StockLocation::query()->ordered()->get() as $location) {
                    if (array_key_exists("location_{$location->id}", $data)) {
                        app(InventoryService::class)->setOnHand($subject, (int) $data["location_{$location->id}"], self::admin(), $data['note'] ?? null, $location);
                    }
                }

                Notification::make()->success()->title(__('Stock saved.'))->send();
            });
    }

    /**
     * @param  Closure(mixed): ?ProductVariant  $variant  the variant of the action's record
     */
    public static function transfer(Closure $variant, string $name = 'transferStock'): Action
    {
        $locations = fn () => StockLocation::query()->where('is_active', true)->ordered()->pluck('name', 'id')->all();

        return Action::make($name)
            ->label('Transfer stock')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (mixed $record) => self::severalLocations() && $variant($record)?->track_inventory)
            ->authorize(fn () => self::canManage())
            ->schema([
                Select::make('from')->label('From')->options($locations)->required()->live(),
                Select::make('to')->label('To')->options($locations)->required()->different('from'),
                TextInput::make('quantity')->integer()->minValue(1)->required()
                    ->helperText(fn (Get $get, mixed $record) => ($subject = $variant($record)) !== null && ($from = StockLocation::query()->whereKey($get('from'))->first()) !== null
                        ? __(':count available there.', ['count' => (int) app(InventoryService::class)->availableAt($subject, $from)])
                        : null),
                TextInput::make('note')->maxLength(500),
            ])
            ->action(function (array $data, mixed $record, Action $action) use ($variant): void {
                $subject = $variant($record);
                $from = StockLocation::query()->whereKey($data['from'])->first();
                $to = StockLocation::query()->whereKey($data['to'])->first();

                if ($subject === null || $from === null || $to === null) {
                    return;
                }

                try {
                    app(InventoryService::class)->transfer($subject, $from, $to, (int) $data['quantity'], self::admin(), $data['note'] ?? null);
                } catch (InsufficientStock) {
                    Notification::make()->danger()->title(__('Not enough stock available at :location.', ['location' => $from->name]))->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(__(':count moved to :location.', ['count' => (int) $data['quantity'], 'location' => $to->name]))->send();
            });
    }

    private static function severalLocations(): bool
    {
        return StockLocation::query()->count() > 1;
    }

    private static function canManage(): bool
    {
        return (bool) auth('admin')->user()?->can('catalog.inventory.manage');
    }

    private static function admin(): ?AdminUser
    {
        $admin = auth('admin')->user();

        return $admin instanceof AdminUser ? $admin : null;
    }
}
