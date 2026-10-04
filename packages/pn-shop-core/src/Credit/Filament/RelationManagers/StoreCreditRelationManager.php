<?php

namespace PnShop\Credit\Filament\RelationManagers;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use PnShop\Acl\Models\AdminUser;
use PnShop\Credit\BalanceReason;
use PnShop\Credit\Balances;
use PnShop\Credit\Filament\BalanceActions;
use PnShop\Credit\Models\CreditAccount;
use PnShop\Customer\Models\User;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Currency;

/**
 * A customer's store credit, one balance per currency.
 */
class StoreCreditRelationManager extends RelationManager
{
    protected static string $relationship = 'creditAccounts';

    protected static ?string $title = 'Store credit';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth('admin')->user()?->can('sales.credit.manage');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('currency'),
                TextColumn::make('balance')->formatStateUsing(fn (mixed $state, CreditAccount $record) => $record->balanceMoney()->formatToLocale(app()->getLocale())),
                TextColumn::make('updated_at')->label('Last change')->since(),
            ])
            ->headerActions([
                Action::make('addCredit')
                    ->label('Add store credit')
                    ->icon(Heroicon::OutlinedPlus)
                    ->schema([
                        Select::make('currency')
                            ->options(fn () => Currency::query()->where('is_active', true)->pluck('name', 'code')->all())
                            ->default(fn () => app(Localization::class)->defaultCurrency()->code)
                            ->required(),
                        TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                        TextInput::make('note')->required()->maxLength(500),
                    ])
                    ->action(function (array $data): void {
                        /** @var User $customer */
                        $customer = $this->getOwnerRecord();
                        $admin = auth('admin')->user();
                        $balances = app(Balances::class);

                        $balances->change(
                            $balances->creditAccount($customer, (string) $data['currency']),
                            Money::of((string) $data['amount'], (string) $data['currency'], roundingMode: RoundingMode::HalfUp),
                            BalanceReason::Adjustment,
                            admin: $admin instanceof AdminUser ? $admin : null,
                            note: $data['note'],
                        );

                        Notification::make()->success()->title(__('Store credit added.'))->send();
                    }),
            ])
            ->recordActions([BalanceActions::adjust(fn (mixed $record) => $record instanceof CreditAccount ? $record : null)]);
    }
}
