<?php

namespace PnShop\Cms\Filament\Resources\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PnShop\Channel\Filament\ChannelsField;
use PnShop\Cms\Filament\ContentEditor;
use PnShop\Cms\Filament\Resources\Pages\Pages\CreatePage;
use PnShop\Cms\Filament\Resources\Pages\Pages\EditPage;
use PnShop\Cms\Filament\Resources\Pages\Pages\ListPages;
use PnShop\Cms\Filament\Resources\Pages\RelationManagers\RevisionsRelationManager;
use PnShop\Cms\Models\Page;
use PnShop\Cms\PageStatus;
use PnShop\Cms\ReservedPaths;
use PnShop\Localization\Filament\TranslationsSection;
use UnitEnum;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        $slugRule = fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && $value !== '' && ReservedPaths::isReserved(Str::slug($value))) {
                $fail(__('This address is used by the shop. Choose another one.'));
            }
        };

        return $schema->columns(3)->components([
            Section::make()->columnSpan(2)->schema([
                TextInput::make('title')->required()->maxLength(255),
                TextInput::make('slug')->label('URL slug')->maxLength(255)->rules([$slugRule])
                    ->helperText('The page address, e.g. "about-us". Generated from the title when empty.'),
                Textarea::make('excerpt')->rows(2)->maxLength(500)->helperText('A short summary for listings and search results.'),
            ]),
            Section::make('Publishing')->columnSpan(1)->schema([
                Select::make('status')->options(PageStatus::class)->default(PageStatus::Draft->value)->required(),
                DateTimePicker::make('published_at')->label('Publish from')->helperText('Leave empty to publish right away; a future date schedules the page.'),
                DateTimePicker::make('unpublished_at')->label('Unpublish at')->after('published_at'),
                Toggle::make('is_home')->label('Use as the homepage'),
                ChannelsField::make(),
            ]),
            ContentEditor::make()->columnSpanFull(),
            Section::make('Search engines')->columnSpanFull()->columns(2)->collapsible()->schema([
                TextInput::make('meta_title')->maxLength(255)->helperText('Defaults to the title.'),
                Textarea::make('meta_description')->rows(2)->maxLength(500)->helperText('Defaults to the excerpt.'),
            ]),
            TranslationsSection::make([
                'title' => fn (string $name) => TextInput::make($name)->label('Title')->maxLength(255),
                'slug' => fn (string $name) => TextInput::make($name)->label('URL slug')->maxLength(255)->rules([$slugRule]),
                'excerpt' => fn (string $name) => Textarea::make($name)->label('Excerpt')->rows(2)->maxLength(500),
                'meta_title' => fn (string $name) => TextInput::make($name)->label('Meta title')->maxLength(255),
                'meta_description' => fn (string $name) => Textarea::make($name)->label('Meta description')->rows(2)->maxLength(500),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->description(fn (Page $record) => '/'.$record->slug),
                TextColumn::make('status')->badge(),
                IconColumn::make('is_home')->label('Homepage')->boolean(),
                TextColumn::make('published_at')->label('Publish from')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(PageStatus::class)])
            ->recordActions([self::previewAction(), EditAction::make(), DeleteAction::make()]);
    }

    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label(fn (Page $record) => $record->isLive() ? 'View' : 'Preview')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->url(fn (Page $record) => URL::temporarySignedRoute('pages.preview', now()->addHour(), ['page' => $record->id]), shouldOpenInNewTab: true);
    }

    public static function getRelations(): array
    {
        return [RevisionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }
}
