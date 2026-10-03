<?php

namespace PnShop\Catalog\Filament\Resources\PriceLists\RelationManagers;

use Brick\Money\Money;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Unique;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\Pricing\Models\PriceList;
use PnShop\Catalog\Pricing\Models\PriceListEntry;
use PnShop\Catalog\Pricing\PriceListEntries;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The prices of a price list, one per variant and starting quantity, with CSV import and
 * export (columns sku, min_quantity, price).
 */
class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Prices';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_variant_id')
                ->label('Variant')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => self::variantOptions($search))
                ->getOptionLabelUsing(fn (mixed $value) => self::variantLabel(ProductVariant::query()->with(['product', 'optionValues'])->whereKey($value)->first())),
            TextInput::make('min_quantity')
                ->label('From quantity')
                ->integer()
                ->minValue(1)
                ->default(1)
                ->required()
                ->unique('price_list_entries', 'min_quantity', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, callable $get) => $rule
                    ->where('price_list_id', $this->getOwnerRecord()->getKey())
                    ->where('product_variant_id', $get('product_variant_id'))),
            TextInput::make('price')->numeric()->minValue(0)->required()->prefix(fn () => $this->priceList()->currency),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['variant.product', 'variant.optionValues']))
            ->defaultSort('product_variant_id')
            ->columns([
                TextColumn::make('variant')->label('Variant')->state(fn (PriceListEntry $record) => self::variantLabel($record->variant)),
                TextColumn::make('variant.sku')->label('SKU')->placeholder('—')->searchable(),
                TextColumn::make('min_quantity')->label('From quantity')->sortable(),
                TextColumn::make('price')->formatStateUsing(fn (?Money $state) => $state?->formatToLocale(app()->getLocale())),
                TextColumn::make('regular')
                    ->label('Regular price')
                    ->color('gray')
                    ->state(fn (PriceListEntry $record) => $record->variant?->price->formatToLocale(app()->getLocale())),
            ])
            ->headerActions([
                CreateAction::make()->label('Add price'),
                Action::make('import')
                    ->label('Import CSV')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->schema([
                        FileUpload::make('file')
                            ->label('CSV file')
                            ->helperText('Columns: sku, min_quantity (optional, default 1), price. An empty price removes that price.')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            ->maxSize(5120)
                            ->disk('local')
                            ->directory('price-imports')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $path = (string) $data['file'];

                        try {
                            $result = app(PriceListEntries::class)->importCsv($this->priceList(), (string) Storage::disk('local')->get($path));
                        } finally {
                            Storage::disk('local')->delete($path);
                        }

                        $notification = Notification::make()
                            ->title(__(':saved prices saved, :removed removed.', ['saved' => $result['saved'], 'removed' => $result['removed']]));

                        if ($result['errors'] !== []) {
                            $notification->warning()->body(implode("\n", array_slice($result['errors'], 0, 8)).(count($result['errors']) > 8 ? "\n…" : ''))->persistent();
                        } else {
                            $notification->success();
                        }

                        $notification->send();
                    }),
                Action::make('export')
                    ->label('Export CSV')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(function (): StreamedResponse {
                        $list = $this->priceList();
                        $csv = app(PriceListEntries::class)->exportCsv($list);

                        return response()->streamDownload(fn () => print ($csv), str($list->name)->slug().'-prices.csv', ['Content-Type' => 'text/csv']);
                    }),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    private function priceList(): PriceList
    {
        /** @var PriceList $list */
        $list = $this->getOwnerRecord();

        return $list;
    }

    /**
     * @return array<int, string>
     */
    private static function variantOptions(string $search): array
    {
        return ProductVariant::query()
            ->with(['product', 'optionValues'])
            ->where(fn (Builder $query) => $query
                ->whereLike('sku', '%'.$search.'%')
                ->orWhereHas('product', fn (Builder $product) => $product->whereLike('title', '%'.$search.'%')))
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (ProductVariant $variant) => [$variant->id => self::variantLabel($variant)])
            ->all();
    }

    private static function variantLabel(?ProductVariant $variant): string
    {
        if ($variant === null) {
            return '—';
        }

        return trim(($variant->product->title ?? '').($variant->label() !== '' ? ' — '.$variant->label() : '').($variant->sku ? " ({$variant->sku})" : ''));
    }
}
