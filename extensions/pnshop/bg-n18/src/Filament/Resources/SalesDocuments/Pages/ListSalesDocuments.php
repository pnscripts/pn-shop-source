<?php

namespace PnShop\Plugins\BgN18\Filament\Resources\SalesDocuments\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use PnShop\Plugins\BgN18\AuditFile;
use PnShop\Plugins\BgN18\Filament\Resources\SalesDocuments\SalesDocumentResource;
use PnShop\Plugins\BgN18\Models\SalesDocument;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListSalesDocuments extends ListRecords
{
    protected static string $resource = SalesDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('auditFile')->label('NRA audit file')->icon(Heroicon::OutlinedDocumentArrowDown)
                ->authorize('viewAny', SalesDocument::class)
                ->modalDescription('The monthly file of Наредба Н-18, Приложение 38: orders delivered in the month and refunds made in it. Due by the 15th of the next month.')
                ->schema([
                    TextInput::make('month')->label('Month (YYYY-MM)')->required()->regex('/^\d{4}-(0[1-9]|1[0-2])$/')
                        ->default(now()->subMonthNoOverflow()->format('Y-m')),
                ])
                ->action(function (array $data): StreamedResponse {
                    [$year, $month] = array_map('intval', explode('-', (string) $data['month']));
                    $xml = app(AuditFile::class)->xml($year, $month);

                    return response()->streamDownload(fn () => print ($xml), sprintf('audit-%04d-%02d.xml', $year, $month), ['Content-Type' => 'application/xml; charset=UTF-8']);
                }),
        ];
    }
}
