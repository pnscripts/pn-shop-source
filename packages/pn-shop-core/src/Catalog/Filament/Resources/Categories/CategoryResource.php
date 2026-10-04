<?php

namespace PnShop\Catalog\Filament\Resources\Categories;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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
use Illuminate\Database\Eloquent\Model;
use PnShop\Catalog\Filament\Resources\Categories\Pages\CreateCategory;
use PnShop\Catalog\Filament\Resources\Categories\Pages\EditCategory;
use PnShop\Catalog\Filament\Resources\Categories\Pages\ListCategories;
use PnShop\Catalog\Models\Category;
use PnShop\Channel\Filament\ChannelsField;
use PnShop\Localization\Filament\TranslationsSection;
use PnShop\Seo\Filament\SeoFields;
use UnitEnum;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('title')->required()->maxLength(255),
                Select::make('parent_id')
                    ->label('Parent category')
                    ->placeholder('None (top level)')
                    ->options(fn (?Category $record) => self::parentOptions($record))
                    ->searchable(),
                TextInput::make('slug')
                    ->maxLength(255)
                    ->helperText('Generated from the title when empty.'),
                Toggle::make('is_active')->label('Visible in the store')->default(true),
                ChannelsField::make(),
                Textarea::make('description')->rows(4)->columnSpanFull(),
            ]),
            SeoFields::section(),
            TranslationsSection::make([
                'title' => fn (string $name) => TextInput::make($name)->label('Title')->maxLength(255),
                'slug' => fn (string $name) => TextInput::make($name)->label('URL slug')->maxLength(255),
                'description' => fn (string $name) => Textarea::make($name)->label('Description')->rows(3),
                ...SeoFields::translations(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('title')
                    ->formatStateUsing(fn (string $state, Category $record) => str_repeat('— ', (int) $record->getAttribute('depth')).$state)
                    ->searchable(),
                TextColumn::make('slug')->color('gray'),
                TextColumn::make('products_count')->counts('products')->label('Products'),
                IconColumn::make('is_active')->label('Visible')->boolean(),
            ])
            ->recordActions([
                Action::make('up')
                    ->label('Up')
                    ->icon(Heroicon::OutlinedArrowUp)
                    ->iconButton()
                    ->authorize(fn (Category $record) => auth('admin')->user()?->can('update', $record) ?? false)
                    ->action(fn (Category $record) => $record->up()),
                Action::make('down')
                    ->label('Down')
                    ->icon(Heroicon::OutlinedArrowDown)
                    ->iconButton()
                    ->authorize(fn (Category $record) => auth('admin')->user()?->can('update', $record) ?? false)
                    ->action(fn (Category $record) => $record->down()),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Model> $query */
        $query = Category::query()->withDepth()->defaultOrder();

        return $query;
    }

    /**
     * Every category except the given one and its descendants (a category cannot move under itself).
     *
     * @return array<int, string>
     */
    public static function parentOptions(?Category $record): array
    {
        $excluded = $record?->exists ? $record->subtreeIds() : [];

        return Category::query()
            ->withDepth()
            ->defaultOrder()
            ->whereNotIn('id', $excluded)
            ->get()
            ->mapWithKeys(fn (Category $category) => [$category->id => str_repeat('— ', (int) $category->getAttribute('depth')).$category->title])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
