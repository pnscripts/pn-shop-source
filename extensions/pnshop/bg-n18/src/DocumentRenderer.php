<?php

namespace PnShop\Plugins\BgN18;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use PnShop\Plugins\BgN18\Models\SalesDocument;

/**
 * A document as a standalone HTML page (emailed as an attachment, downloaded in the admin).
 */
final class DocumentRenderer
{
    public function html(SalesDocument $document): string
    {
        $data = $document->data;
        $qr = is_string($data['qr'] ?? null) && $data['qr'] !== ''
            ? (new QRCode(new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'outputBase64' => true, 'addQuietzone' => true])))->render($data['qr'])
            : null;

        return view('pnshop_bg_n18::document', [
            'document' => $document,
            'data' => $data,
            'issuedAt' => $document->issued_at->copy()->setTimezone((string) ($data['timezone'] ?? 'UTC')),
            'qr' => $qr,
        ])->render();
    }

    public function filename(SalesDocument $document): string
    {
        return $document->type === SalesDocument::SALE
            ? 'document-'.$document->formattedNumber().'.html'
            : 'document-'.preg_replace('/[^A-Za-z0-9-]/', '', (string) ($document->data['order_number'] ?? $document->order_id)).'.html';
    }
}
