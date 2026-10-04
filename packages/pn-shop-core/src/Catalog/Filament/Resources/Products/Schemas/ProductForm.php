<?php

namespace PnShop\Catalog\Filament\Resources\Products\Schemas;

use Brick\Money\Money;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use PnShop\Catalog\Filament\Resources\Categories\CategoryResource;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductAttribute;
use PnShop\Catalog\ProductRelationType;
use PnShop\Catalog\ProductType;
use PnShop\Channel\Filament\ChannelsField;
use PnShop\Inventory\InventoryService;
use PnShop\Localization\Filament\TranslationsSection;
use PnShop\Media\MediaLibrary;
use PnShop\Media\Models\Media;
use PnShop\Seo\Filament\SeoFields;

class ProductForm
{
    /** Stock changes need catalog.inventory.manage, not only the right to edit products. */
    public static function canManageStock(): bool
    {
        return (bool) auth('admin')->user()?->can('catalog.inventory.manage');
    }

    public static function configure(Schema $schema): Schema
    {
        $isSimple = fn (Get $get) => ($get('type') instanceof ProductType ? $get('type') : ProductType::tryFrom((string) $get('type'))) !== ProductType::Variable;

        return $schema
            ->columns(3)
            ->components([
                Section::make('Product')
                    ->columnSpan(2)
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->options(collect(ProductType::cases())->mapWithKeys(fn (ProductType $type) => [$type->value => $type->label()]))
                            ->default(ProductType::Simple->value)
                            ->required()
                            ->live()
                            ->helperText('Products with variants have options such as size or color, each combination with its own price and stock.'),
                        Select::make('product_category_id')
                            ->label('Primary category')
                            ->helperText('Used for breadcrumbs and the product\'s main URL.')
                            ->options(fn () => CategoryResource::parentOptions(null))
                            ->searchable()
                            ->required(),
                        Select::make('categories')
                            ->label('Also show in')
                            ->multiple()
                            ->relationship('categories', 'title')
                            ->options(fn () => CategoryResource::parentOptions(null))
                            ->searchable()
                            // The primary category always stays among the product's categories.
                            ->saveRelationshipsUsing(fn (Product $record, ?array $state) => $record->categories()->sync(
                                array_values(array_unique([...array_map('intval', $state ?? []), (int) $record->product_category_id])),
                            )),
                        Select::make('brand_id')
                            ->label('Brand')
                            ->relationship('brand', 'name')
                            ->searchable()
                            ->preload(),
                        Textarea::make('description')
                            ->rows(6),
                        FileUpload::make('gallery')
                            ->label('Images')
                            ->helperText('The first image is the main product image. Drag to reorder.')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->acceptedFileTypes(MediaLibrary::IMAGE_TYPES)
                            ->maxSize(MediaLibrary::MAX_UPLOAD_KB)
                            ->disk(MediaLibrary::disk())
                            ->directory(fn () => MediaLibrary::directory())
                            ->afterStateHydrated(fn (FileUpload $component, ?Product $record) => $component->state(
                                $record?->mediaIn('gallery')->mapWithKeys(fn (Media $media) => [(string) Str::uuid() => $media->path])->all() ?? [],
                            ))
                            ->dehydrated(false)
                            ->saveRelationshipsUsing(function (FileUpload $component, Product $record): void {
                                $component->saveUploadedFiles();
                                $library = app(MediaLibrary::class);

                                $record->syncMediaCollection('gallery', array_map(
                                    fn (mixed $path) => $library->register((string) $path)->id,
                                    array_values((array) $component->getState()),
                                ));
                            }),
                        TextInput::make('image')
                            ->label('External image URL')
                            ->helperText('Only used when the product has no uploaded images.')
                            ->url()
                            ->maxLength(2048),
                    ]),
                Section::make('Visibility')
                    ->columnSpan(1)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Visible in the store')
                            ->default(true),
                        Toggle::make('is_featured')
                            ->label('Featured on the home page')
                            ->helperText('Featured products come first in the home page\'s product list.'),
                        ChannelsField::make(),
                        Select::make('tax_class_id')
                            ->label('Tax class')
                            ->relationship('taxClass', 'name')
                            ->placeholder('Default class')
                            ->preload(),
                    ]),
                Section::make('Pricing and inventory')
                    ->description('Stored on the product\'s single variant.')
                    ->columnSpan(1)
                    ->visible($isSimple)
                    ->schema([
                        self::shortcut(TextInput::make('price')->required()->numeric()->minValue(0)),
                        self::shortcut(TextInput::make('sale_price')
                            ->label('Sale price')
                            ->numeric()
                            ->minValue(0)
                            ->lt('price')
                            ->helperText('Leave empty when the product is not on sale.')),
                        TextInput::make('stock')
                            ->label(fn () => InventoryService::stockFieldLabel())
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->disabled(fn () => ! self::canManageStock())
                            ->dehydrated(fn () => self::canManageStock())
                            // `stock` reads as *available*; the form edits what is on the shelf,
                            // which includes units reserved for open orders.
                            ->afterStateHydrated(fn (TextInput $component, ?Product $record) => $record === null ? null : $component->state(
                                ($variant = $record->defaultVariant()) === null ? 0 : app(InventoryService::class)->onHandAt($variant),
                            ))
                            ->helperText(fn (?Product $record) => self::stockHelp($record)),
                        self::shortcut(TextInput::make('sku')
                            ->label('SKU')
                            ->maxLength(255)
                            ->unique('product_variants', 'sku', ignoreRecord: false, modifyRuleUsing: fn (Unique $rule, ?Product $record) => $rule->ignore($record?->defaultVariant()?->id))),
                        self::shortcut(TextInput::make('barcode')->maxLength(255)),
                        self::shortcut(TextInput::make('weight')->label('Weight (grams)')->integer()->minValue(0)),
                    ]),
                Section::make('Attributes')
                    ->description('Specifications shown on the product page and used by shop filters.')
                    ->columnSpan(1)
                    ->schema([
                        Select::make('selectedAttributeValues')
                            ->hiddenLabel()
                            ->relationship('selectedAttributeValues', 'value')
                            ->options(fn () => ProductAttribute::query()->with('values')->orderBy('position')->get()
                                ->mapWithKeys(fn (ProductAttribute $attribute) => [$attribute->label => $attribute->values->pluck('value', 'id')->all()])
                                ->all())
                            ->multiple()
                            ->searchable(),
                    ]),
                Section::make('Options')
                    ->description('Choose the options this product varies by, then add variants below.')
                    ->columnSpan(1)
                    ->hidden($isSimple)
                    ->schema([
                        Select::make('options')
                            ->hiddenLabel()
                            ->relationship('options', 'name')
                            ->multiple()
                            ->preload(),
                    ]),
                Section::make('Related products')
                    ->columnSpanFull()
                    ->columns(3)
                    ->collapsible()
                    ->schema(array_map(fn (ProductRelationType $type) => Select::make($type->relationName())
                        ->label($type->label())
                        ->relationship($type->relationName(), 'title', fn ($query, ?Product $record) => $query->whereKeyNot($record->id ?? 0))
                        ->multiple()
                        ->searchable()
                        // Keep the chosen order as the display order.
                        ->saveRelationshipsUsing(fn (Product $record, ?array $state) => $record->{$type->relationName()}()->sync(
                            collect($state ?? [])->values()->mapWithKeys(fn (mixed $id, int $position) => [(int) $id => ['position' => $position]])->all(),
                        )), ProductRelationType::cases())),
                SeoFields::section()->columnSpanFull(),
                TranslationsSection::make([
                    'title' => fn (string $name) => TextInput::make($name)->label('Title')->maxLength(255),
                    'slug' => fn (string $name) => TextInput::make($name)->label('URL slug')->maxLength(255)
                        ->helperText('Generated from the title when empty.'),
                    'description' => fn (string $name) => Textarea::make($name)->label('Description')->rows(6),
                    ...SeoFields::translations(),
                ])->columnSpanFull(),
            ]);
    }

    private static function stockHelp(?Product $record): string
    {
        $variant = $record?->defaultVariant();
        $reserved = $variant === null ? 0 : app(InventoryService::class)->reservedAt($variant);

        return $reserved > 0
            ? "Includes {$reserved} reserved for open orders. Changes are recorded in the stock history."
            : 'Changes are recorded in the stock history.';
    }

    /**
     * A field backed by a Product variant shortcut (price, stock, ...): filled from the
     * default variant and saved back to it through the model's shortcut attributes.
     */
    private static function shortcut(Field $field): Field
    {
        return $field->afterStateHydrated(function (Field $component, ?Product $record) use ($field): void {
            if ($record === null) {
                return;
            }

            $value = $record->getAttribute($field->getName());
            $component->state($value instanceof Money ? (string) $value->getAmount() : $value);
        });
    }
}
