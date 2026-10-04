<?php

namespace PnShop\Credit\Filament;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use PnShop\Acl\Models\AdminUser;
use PnShop\Credit\BalanceReason;
use PnShop\Credit\Balances;
use PnShop\Credit\Contracts\BalanceAccount;
use PnShop\Credit\Exceptions\InsufficientBalance;
use PnShop\Credit\Models\BalanceTransaction;
use PnShop\Sales\Filament\Resources\Orders\OrderResource;

/**
 * Admin pieces shared by gift cards and store credit: adjusting a balance and listing its ledger.
 */
final class BalanceActions
{
    /**
     * @param  Closure(mixed): ((BalanceAccount&Model)|null)  $account
     */
    public static function adjust(Closure $account, string $name = 'adjustBalance'): Action
    {
        return Action::make($name)
            ->label('Adjust balance')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->authorize(fn () => (bool) auth('admin')->user()?->can('sales.credit.manage'))
            ->schema([
                TextInput::make('amount')->numeric()->required()->notIn(['0'])->helperText('Positive to add, negative to take away.'),
                TextInput::make('note')->required()->maxLength(500),
            ])
            ->action(function (array $data, mixed $record, Action $action) use ($account): void {
                $subject = $account($record);

                if ($subject === null) {
                    return;
                }

                $admin = auth('admin')->user();

                try {
                    app(Balances::class)->change(
                        $subject,
                        Money::of((string) $data['amount'], $subject->balanceMoney()->getCurrency(), roundingMode: RoundingMode::HalfUp),
                        BalanceReason::Adjustment,
                        admin: $admin instanceof AdminUser ? $admin : null,
                        note: $data['note'],
                    );
                } catch (InsufficientBalance $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(__('New balance: :amount', ['amount' => $subject->balanceMoney()->formatToLocale(app()->getLocale())]))->send();
            });
    }

    /**
     * Columns of a ledger table.
     *
     * @return list<TextColumn>
     */
    public static function ledgerColumns(): array
    {
        return [
            TextColumn::make('created_at')->label('When')->dateTime(),
            TextColumn::make('reason')->badge()->formatStateUsing(fn (BalanceReason $state) => $state->label()),
            TextColumn::make('amount')
                ->label('Change')
                ->formatStateUsing(fn (mixed $state, BalanceTransaction $record) => ($record->amount > 0 ? '+' : '').$record->amountMoney()->formatToLocale(app()->getLocale()))
                ->color(fn (BalanceTransaction $record) => $record->amount < 0 ? 'danger' : 'success'),
            TextColumn::make('balance_after')
                ->label('Balance after')
                ->formatStateUsing(fn (mixed $state, BalanceTransaction $record) => Money::ofMinor($record->balance_after, $record->currency)->formatToLocale(app()->getLocale())),
            TextColumn::make('order.number')->label('Order')->placeholder('—')
                ->url(fn (BalanceTransaction $record) => $record->order_id !== null ? OrderResource::getUrl('view', ['record' => $record->order_id]) : null),
            TextColumn::make('adminUser.name')->label('By')->placeholder('—'),
            TextColumn::make('note')->placeholder('—')->limit(60),
        ];
    }
}
