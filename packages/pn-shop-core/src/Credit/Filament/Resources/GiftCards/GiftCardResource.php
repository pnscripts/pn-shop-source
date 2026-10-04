<?php

namespace PnShop\Credit\Filament\Resources\GiftCards;

use BackedEnum;
use Brick\Money\Money;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use PnShop\Credit\Filament\BalanceActions;
use PnShop\Credit\Filament\Resources\GiftCards\Pages\CreateGiftCard;
use PnShop\Credit\Filament\Resources\GiftCards\Pages\EditGiftCard;
use PnShop\Credit\Filament\Resources\GiftCards\Pages\ListGiftCards;
use PnShop\Credit\Filament\Resources\GiftCards\RelationManagers\TransactionsRelationManager;
use PnShop\Credit\Models\GiftCard;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Currency;
use UnitEnum;

/**
 * Gift cards: issue (the code is shown once and emailed to the recipient), adjust, disable.
 * Search with the full code or its last four characters.
 */
class GiftCardResource extends Resource
{
    protected static ?string $model = GiftCard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('amount')->numeric()->minValue(0.01)->required()->visibleOn('create'),
                Select::make('currency')
                    ->options(fn () => Currency::query()->where('is_active', true)->pluck('name', 'code')->all())
                    ->default(fn () => app(Localization::class)->defaultCurrency()->code)
                    ->required()
                    ->visibleOn('create'),
                DateTimePicker::make('expires_at')->label('Expires')->helperText('Empty: never.'),
                TextInput::make('recipient_email')->label('Send to')->email()->maxLength(255)->helperText('The code is emailed here when the card is issued.'),
                Toggle::make('is_active')->label('Active')->default(true)->visibleOn('edit'),
                Textarea::make('note')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('last4')
                    ->label('Card')
                    ->formatStateUsing(fn (string $state) => '•••• '.$state)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                        ->where('code_hash', GiftCard::hash($search))
                        ->orWhere('last4', strtoupper(trim($search))))),
                TextColumn::make('balance')->formatStateUsing(fn (mixed $state, GiftCard $record) => $record->balanceMoney()->formatToLocale(app()->getLocale()))->sortable(),
                TextColumn::make('initial_amount')->label('Issued for')->color('gray')
                    ->formatStateUsing(fn (mixed $state, GiftCard $record) => Money::ofMinor($record->initial_amount, $record->currency)->formatToLocale(app()->getLocale())),
                TextColumn::make('recipient_email')->label('Sent to')->placeholder('—')->searchable(),
                TextColumn::make('expires_at')->label('Expires')->date()->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                BalanceActions::adjust(fn (mixed $record) => $record instanceof GiftCard ? $record : null),
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [TransactionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGiftCards::route('/'),
            'create' => CreateGiftCard::route('/create'),
            'edit' => EditGiftCard::route('/{record}/edit'),
        ];
    }
}
