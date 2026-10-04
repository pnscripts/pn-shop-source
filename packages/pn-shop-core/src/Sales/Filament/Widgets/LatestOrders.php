<?php

namespace PnShop\Sales\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use PnShop\Channel\Models\Channel;
use PnShop\Sales\Filament\Resources\Orders\OrderResource;
use PnShop\Sales\Models\Order;

class LatestOrders extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('sales.orders.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Order::query()->with(['paymentMethod', 'channel'])->when($this->pageFilters['channel_id'] ?? null, fn (Builder $query, mixed $channel) => $query->where('channel_id', (int) $channel))->latest()->limit(5))
            ->paginated(false)
            ->columns([
                TextColumn::make('number')->label('Order'),
                TextColumn::make('name')->label('Customer'),
                TextColumn::make('channel.name')->label('Channel')->visible(fn () => Channel::query()->count() > 1),
                TextColumn::make('status')->badge(),
                TextColumn::make('payment_status')->label('Payment')->badge(),
                TextColumn::make('created_at')->label('Placed')->since(),
            ])
            ->recordActions([
                Action::make('view')->url(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
