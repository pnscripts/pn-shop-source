<!doctype html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document->type === 'sale' ? 'Документ за регистриране на продажба № '.$document->formattedNumber() : 'Документ за продажба към поръчка '.$data['order_number'] }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; margin: 0; padding: 24px; background: #fff; }
        main { max-width: 760px; margin: 0 auto; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        p { margin: 2px 0; }
        .muted { color: #555; font-size: 13px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 20px 0; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; margin-top: 12px; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px 4px; text-align: left; vertical-align: top; }
        th.num, td.num { text-align: right; white-space: nowrap; }
        tfoot td { border-bottom: none; }
        .total td { font-weight: 700; font-size: 16px; }
        .qr { margin-top: 20px; }
        .qr img { width: 160px; height: 160px; }
        @media (max-width: 560px) { .grid { grid-template-columns: 1fr; } body { padding: 16px; } }
        @media print { body { padding: 0; } }
    </style>
</head>
<body>
<main>
    @if ($document->type === 'sale')
        <h1>Документ за регистриране на продажба</h1>
        <p class="muted">Sales document · № {{ $document->formattedNumber() }} · {{ $issuedAt->format('d.m.Y H:i:s') }}</p>
    @else
        <h1>Документ за продажба</h1>
        <p class="muted">Sales document for cash on delivery (postal money order) · {{ $issuedAt->format('d.m.Y H:i:s') }}</p>
    @endif

    <div class="grid">
        <section>
            <p class="muted">Продавач / Seller</p>
            <p><strong>{{ $data['seller']['name'] }}</strong></p>
            <p>ЕИК {{ $data['seller']['eik'] }}</p>
            <p>{{ $data['seller']['address'] }}</p>
            <p>{{ $data['seller']['contact'] }}</p>
        </section>
        <section>
            <p class="muted">Поръчка / Order</p>
            <p><strong>{{ $data['order_number'] }}</strong></p>
            <p>Клиент / Customer: {{ $data['customer'] }}</p>
            @if ($document->transaction_ref)
                <p>Референтен номер на трансакцията / Transaction: {{ $document->transaction_ref }}</p>
            @endif
            <p>Начин на плащане / Payment: {{ $data['payment'] }}</p>
        </section>
    </div>

    <table>
        <thead>
        <tr>
            <th>Стока или услуга</th>
            <th class="num">Група</th>
            <th class="num">Кол.</th>
            <th class="num">Ед. цена</th>
            <th class="num">Стойност</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($data['lines'] as $line)
            <tr>
                <td>{{ $line['name'] }}</td>
                <td class="num">{{ $line['vat_group'] }} ({{ $line['vat_rate'] }}%)</td>
                <td class="num">{{ rtrim(rtrim($line['quantity'], '0'), '.') }}</td>
                <td class="num">{{ $line['unit_price_gross'] }}</td>
                <td class="num">{{ $line['total'] }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr><td colspan="4">Стойност без ДДС / Net</td><td class="num">{{ number_format((float) $data['total_net'] - (float) $data['discount'], 2, '.', '') }}</td></tr>
        @if ((float) $data['discount'] > 0)
            <tr><td colspan="4">Отстъпка без ДДС / Discount</td><td class="num">−{{ $data['discount'] }}</td></tr>
        @endif
        <tr><td colspan="4">ДДС / VAT</td><td class="num">{{ $data['vat'] }}</td></tr>
        <tr class="total"><td colspan="4">Обща сума / Total</td><td class="num">{{ $data['total'] }} {{ $data['currency'] === 'EUR' ? '€' : $data['currency'] }}</td></tr>
        </tfoot>
    </table>

    @if ($qr)
        <div class="qr"><img src="{{ $qr }}" alt="QR: {{ $data['qr'] }}"></div>
    @endif
</main>
</body>
</html>
