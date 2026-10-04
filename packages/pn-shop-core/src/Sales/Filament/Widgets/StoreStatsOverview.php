<?php

namespace PnShop\Sales\Filament\Widgets;

use Brick\Money\Money;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use PnShop\Catalog\Models\Product;
use PnShop\Sales\Models\Order;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\OrderStatus;

class StoreStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('sales.orders.view') ?? false;
    }

    protected function getStats(): array
    {
        $orders = fn () => Order::query()->when($this->pageFilters['channel_id'] ?? null, fn (Builder $query, mixed $channel) => $query->where('channel_id', (int) $channel));

        return [
            Stat::make('Orders today', $orders()->whereDate('created_at', today())->count()),
            Stat::make('Sales today', $this->salesToday($orders())),
            Stat::make('Pending orders', $orders()->where('status', OrderStatus::Pending)->count()),
            Stat::make('Awaiting shipment', $orders()
                ->whereIn('status', [OrderStatus::Pending, OrderStatus::Processing])
                ->whereIn('fulfillment_status', [FulfillmentStatus::Unfulfilled, FulfillmentStatus::PartiallyFulfilled])
                ->count()),
            Stat::make('Visible products', Product::query()->active()->count()),
        ];
    }

    /**
     * Today's order totals (cancelled ones left out), per currency: channels can sell in
     * different currencies.
     *
     * @param  Builder<Order>  $orders
     */
    private function salesToday(Builder $orders): string
    {
        $totals = $orders->whereDate('created_at', today())
            ->where('status', '!=', OrderStatus::Cancelled)
            ->selectRaw('currency, sum(total) as amount')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        if ($totals->isEmpty()) {
            return '—';
        }

        return $totals->map(fn (Order $row) => Money::ofMinor((int) $row->getRawOriginal('amount'), (string) $row->currency)->formatToLocale(app()->getLocale()))->implode(' · ');
    }
}
