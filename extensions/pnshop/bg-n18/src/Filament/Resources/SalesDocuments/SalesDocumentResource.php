<?php

namespace PnShop\Plugins\BgN18\Filament\Resources\SalesDocuments;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use PnShop\Plugins\BgN18\DocumentRenderer;
use PnShop\Plugins\BgN18\Filament\Resources\SalesDocuments\Pages\ListSalesDocuments;
use PnShop\Plugins\BgN18\Models\SalesDocument;
use PnShop\Sales\Filament\Resources\Orders\OrderResource;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class SalesDocumentResource extends Resource
{
    protected static ?string $model = SalesDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Sales documents (Н-18)';

    protected static ?string $modelLabel = 'sales document';

    protected static ?string $slug = 'sales-documents';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('Number')->formatStateUsing(fn (SalesDocument $record) => $record->formattedNumber())->placeholder('—')->searchable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => $state === SalesDocument::SALE ? 'Sale document' : 'Cash on delivery'),
                TextColumn::make('issued_at')->label('Issued')->dateTime()->sortable(),
                TextColumn::make('order.number')->label('Order')->searchable()
                    ->url(fn (SalesDocument $record) => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextColumn::make('total')->formatStateUsing(fn (SalesDocument $record) => number_format($record->total / 100, 2, '.', '').' '.$record->currency),
                TextColumn::make('emailed_at')->label('Emailed')->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('type')->options([SalesDocument::SALE => 'Sale document', SalesDocument::COD => 'Cash on delivery']),
            ])
            ->recordActions([
                Action::make('download')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)
                    ->authorize('view')
                    ->action(fn (SalesDocument $record): StreamedResponse => self::download($record)),
            ]);
    }

    public static function download(SalesDocument $document): StreamedResponse
    {
        $renderer = app(DocumentRenderer::class);
        $html = $renderer->html($document);

        return response()->streamDownload(fn () => print ($html), $renderer->filename($document), ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function getPages(): array
    {
        return ['index' => ListSalesDocuments::route('/')];
    }
}
