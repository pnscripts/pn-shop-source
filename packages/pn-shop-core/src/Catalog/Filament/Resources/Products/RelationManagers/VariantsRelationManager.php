<?php

namespace PnShop\Catalog\Filament\Resources\Products\RelationManagers;

use Brick\Money\Money;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use PnShop\Catalog\Exceptions\InvalidVariant;
use PnShop\Catalog\Filament\Resources\Products\Schemas\ProductForm;
use PnShop\Catalog\Filament\Widgets\LowStockProducts;
use PnShop\Catalog\Models\Option;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\ProductType;
use PnShop\Catalog\VariantService;
use PnShop\Inventory\Filament\StockActions;
use PnShop\Inventory\InventoryService;

/**
 * Variants of a product with options: one per combination of option values.
 */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Variants';

    /** Variants are the heart of a variable product's edit page; render them with the page. */
    protected static bool $isLazy = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Product && $ownerRecord->type === ProductType::Variable;
    }

    public function form(Schema $schema): Schema
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return $schema->components([
            Grid::make(max(1, $product->options->count()))->schema(
                $product->options->map(fn (Option $option) => Select::make("option_{$option->id}")
                    ->label($option->name)
                    ->options($option->values->pluck('value', 'id'))
                    ->required()
                )->all(),
            )->columnSpanFull(),
            TextInput::make('price')->numeric()->minValue(0)->required(),
            TextInput::make('sale_price')->label('Sale price')->numeric()->minValue(0)->lt('price'),
            TextInput::make('sku')
                ->label('SKU')
                ->maxLength(255)
                ->unique('product_variants', 'sku', ignoreRecord: true),
            TextInput::make('barcode')->maxLength(255),
            TextInput::make('weight')->label('Weight (grams)')->integer()->minValue(0),
            TextInput::make('stock')->label(fn () => InventoryService::stockFieldLabel())->integer()->minValue(0)->default(0)
                ->disabled(fn () => ! ProductForm::canManageStock())
                ->dehydrated(fn () => ProductForm::canManageStock())
                ->helperText('Changes are recorded in the stock history.'),
            Toggle::make('is_active')->label('Available')->default(true),
            Toggle::make('track_inventory')->label('Track stock')->default(true),
            Toggle::make('allow_backorder')->label('Sell when out of stock'),
            TextInput::make('low_stock_threshold')->label('Low stock at')->integer()->minValue(0)
                ->placeholder((string) LowStockProducts::THRESHOLD)
                ->helperText('The dashboard lists the variant when this many units or fewer are left. Empty: '.LowStockProducts::THRESHOLD.'.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['optionValues', 'stockLevels']))
            ->reorderable('position')
            ->columns([
                TextColumn::make('label')
                    ->label('Variant')
                    ->state(fn (ProductVariant $record) => $record->label() ?: '—'),
                TextColumn::make('sku')->label('SKU')->placeholder('—'),
                TextColumn::make('price')->formatStateUsing(fn (?Money $state) => $state?->formatToLocale(app()->getLocale())),
                TextColumn::make('sale_price')->label('Sale')->formatStateUsing(fn (?Money $state) => $state?->formatToLocale(app()->getLocale()))->placeholder('—'),
                TextColumn::make('available')
                    ->label('Stock')
                    ->state(fn (ProductVariant $record) => $record->available() ?? '∞')
                    ->color(fn (mixed $state) => $state === 0 ? 'danger' : null),
                IconColumn::make('is_default')->label('Default')->boolean(),
                IconColumn::make('is_active')->label('Available')->boolean(),
            ])
            ->headerActions([
                Action::make('generate')
                    ->label('Generate variants')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->requiresConfirmation()
                    ->modalDescription('Creates one variant for every combination of option values that does not exist yet, priced like the default variant.')
                    ->action(function (Action $action): void {
                        try {
                            $created = app(VariantService::class)->generate($this->ownerProduct());
                        } catch (InvalidVariant $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();

                            return;
                        }

                        Notification::make()->success()->title("{$created} variant(s) created.")->send();
                    }),
                CreateAction::make()
                    ->using(fn (array $data) => $this->saveVariant(new ProductVariant(['product_id' => $this->getOwnerRecord()->getKey()]), $data)),
            ])
            ->recordActions([
                StockActions::byLocation(fn (mixed $record) => $record instanceof ProductVariant ? $record : null),
                StockActions::transfer(fn (mixed $record) => $record instanceof ProductVariant ? $record : null),
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, ProductVariant $record) => $this->fillVariantData($data, $record))
                    ->using(fn (ProductVariant $record, array $data) => $this->saveVariant($record, $data)),
                DeleteAction::make()
                    ->using(function (ProductVariant $record, DeleteAction $action): bool {
                        try {
                            app(VariantService::class)->delete($record);
                        } catch (InvalidVariant $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }

                        return true;
                    }),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillVariantData(array $data, ProductVariant $record): array
    {
        foreach ($record->optionValues as $value) {
            $data["option_{$value->option_id}"] = $value->id;
        }

        $data['price'] = (string) $record->price->getAmount();
        $data['sale_price'] = $record->sale_price !== null ? (string) $record->sale_price->getAmount() : null;
        $data['stock'] = app(InventoryService::class)->onHandAt($record);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveVariant(ProductVariant $variant, array $data): ProductVariant
    {
        $product = $this->ownerProduct();
        $valueIds = array_values($product->options->map(fn (Option $option) => (int) $data["option_{$option->id}"])->all());

        try {
            return app(VariantService::class)->save(
                $product,
                $variant,
                $data,
                $valueIds,
                array_key_exists('stock', $data) && ProductForm::canManageStock() ? (int) $data['stock'] : null,
                auth('admin')->user(),
            );
        } catch (InvalidVariant $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
    }

    private function ownerProduct(): Product
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return $product;
    }
}
