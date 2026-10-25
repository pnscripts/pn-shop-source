<?php

namespace Tests\Feature\Extensions;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PnShop\Plugins\BgN18\AuditFile;
use PnShop\Plugins\BgN18\DocumentRenderer;
use PnShop\Plugins\BgN18\Models\SalesDocument;
use PnShop\Plugins\BgN18\Notifications\SalesDocumentIssued;
use PnShop\Plugins\BgN18\OrderSnapshot;
use PnShop\Plugins\BgN18\SalesDocuments;
use PnShop\Sales\Models\Order;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\PaymentStatus;
use PnShop\Settings\Models\Setting;
use PnShop\Settings\Settings;
use PnShop\Shipping\ShipmentService;

/**
 * The Наредба Н-18 plugin (extensions/pnshop/bg-n18).
 */
class BgN18PluginTest extends ShippedPluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enablePlugin('pnshop/bg-n18');
        app(Settings::class)->set('plugin.pnshop_bg_n18', [
            'seller_name' => 'Example EOOD',
            'eik' => '123456789',
            'seller_address' => 'Sofia, 1 Example St.',
            'seller_contact' => 'shop@example.com',
            'e_shop_number' => 'RF0000001',
            'domain' => 'shop.example',
            'providers' => 'stripe=IE3206651KH Stripe Payments Europe Ltd.',
        ]);
        app(Settings::class)->set('localization', ['timezone' => 'Europe/Sofia']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Two mugs at 12.00 with 20 % VAT included, delivery 6.00 (VAT 1.00 included).
     */
    private function order(string $gateway, string $number): Order
    {
        $order = Order::factory()->create(['currency' => 'EUR', 'email' => 'ana@example.com']);
        $order->forceFill([
            'number' => $number,
            'subtotal' => '24.00',
            'total' => '30.00',
            'totals' => [
                ['code' => 'shipping', 'label' => 'Delivery', 'amount' => 600, 'included' => false],
                ['code' => 'tax', 'label' => 'VAT 20%', 'amount' => 500, 'included' => true],
            ],
        ])->saveQuietly();
        $order->items()->create(['product_title' => 'Mug', 'quantity' => 2, 'currency' => 'EUR', 'price' => '12.00', 'tax_amount' => '4.00', 'discount_amount' => '0.00']);
        $order->payments()->create(['gateway' => $gateway, 'currency' => 'EUR', 'amount' => '30.00', 'reference' => $gateway === 'stripe' ? 'pi_'.$number : null]);

        return $order->refresh();
    }

    public function test_the_snapshot_splits_net_vat_and_charges(): void
    {
        $snapshot = OrderSnapshot::of($this->order('stripe', 'ORD-1')->load('items'));

        $this->assertSame(['name' => 'Mug', 'quantity' => '2.00', 'unit_price' => '10.00', 'unit_price_gross' => '12.00', 'vat_rate' => 20, 'vat_group' => 'Б', 'vat' => '4.00', 'total' => '24.00'], $snapshot['lines'][0]);
        $this->assertSame(['name' => 'Delivery', 'quantity' => '1.00', 'unit_price' => '5.00', 'unit_price_gross' => '6.00', 'vat_rate' => 20, 'vat_group' => 'Б', 'vat' => '1.00', 'total' => '6.00'], $snapshot['lines'][1]);
        $this->assertSame(['25.00', '0.00', '5.00', '30.00'], [$snapshot['total_net'], $snapshot['discount'], $snapshot['vat'], $snapshot['total']]);
    }

    public function test_card_payments_get_a_numbered_sale_document_with_the_qr_code(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-20 09:30:15'); // 12:30:15 in Sofia (summer time)

        $order = $this->order('stripe', 'ORD-000042');
        app(OrderWorkflow::class)->transition($order, PaymentStatus::Paid);

        $document = SalesDocument::query()->sole();
        $this->assertSame('0000000001', $document->formattedNumber());
        $this->assertSame('pi_ORD-000042', $document->transaction_ref);
        $this->assertSame('RF0000001*ORD-000042*pi_ORD-000042*2026-10-20*12:30:15*30.00', $document->data['qr']);

        Notification::assertSentTo(new AnonymousNotifiable, SalesDocumentIssued::class, function (SalesDocumentIssued $notification, array $channels, AnonymousNotifiable $notifiable) {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'ana@example.com' && $mail->rawAttachments[0]['name'] === 'document-0000000001.html';
        });

        $html = app(DocumentRenderer::class)->html($document);
        $this->assertStringContainsString('Документ за регистриране на продажба', $html);
        $this->assertStringContainsString('ЕИК 123456789', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);

        // Issuing again returns the same document; the next order gets the next number.
        $this->assertTrue($document->is(app(SalesDocuments::class)->issueSaleDocument($order)));
        app(OrderWorkflow::class)->transition($this->order('stripe', 'ORD-000043'), PaymentStatus::Paid);
        $this->assertSame([1, 2], SalesDocument::query()->orderBy('id')->pluck('number')->all());
    }

    public function test_numbering_continues_from_the_chosen_first_number(): void
    {
        Notification::fake();
        app(Settings::class)->set('plugin.pnshop_bg_n18', ['first_number' => 5000]);

        app(OrderWorkflow::class)->transition($this->order('stripe', 'ORD-1'), PaymentStatus::Paid);
        app(OrderWorkflow::class)->transition($this->order('stripe', 'ORD-2'), PaymentStatus::Paid);

        $this->assertSame([5000, 5001], SalesDocument::query()->orderBy('id')->pluck('number')->all());
    }

    public function test_bank_transfers_need_no_document_and_incomplete_settings_issue_none(): void
    {
        Notification::fake();
        app(OrderWorkflow::class)->transition($this->order('bank_transfer', 'ORD-1'), PaymentStatus::Paid);
        $this->assertSame(0, SalesDocument::query()->count());

        // The e-shop number is required in the settings form; a shop that never filled it in has none.
        Setting::query()->where('namespace', 'plugin.pnshop_bg_n18')->where('key', 'e_shop_number')->delete();
        app(Settings::class)->flush();
        app(OrderWorkflow::class)->transition($this->order('stripe', 'ORD-2'), PaymentStatus::Paid);
        $this->assertSame(0, SalesDocument::query()->count());
    }

    public function test_cash_on_delivery_gets_its_document_when_shipped(): void
    {
        Notification::fake();
        $order = $this->order('cod', 'ORD-7');

        app(ShipmentService::class)->ship($order);

        $document = SalesDocument::query()->sole();
        $this->assertSame(SalesDocument::COD, $document->type);
        $this->assertNull($document->number);
        $this->assertNull($document->data['qr']);
        Notification::assertSentTo(new AnonymousNotifiable, SalesDocumentIssued::class);
    }

    public function test_the_audit_file_lists_the_months_orders_and_refunds(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-10 10:00');
        $card = $this->order('stripe', 'ORD-1');
        app(OrderWorkflow::class)->transition($card, PaymentStatus::Paid);
        app(ShipmentService::class)->ship($card);
        $cod = $this->order('cod', 'ORD-2');
        app(ShipmentService::class)->ship($cod);
        $card->refunds()->create(['currency' => 'EUR', 'amount' => '6.00', 'status' => 'completed', 'destination' => 'original']);
        // Delivered next month: not in October's file.
        Carbon::setTestNow('2026-11-02 10:00');
        app(ShipmentService::class)->ship($this->order('cod', 'ORD-3'));

        $xml = simplexml_load_string(app(AuditFile::class)->xml(2026, 10));

        $this->assertNotFalse($xml);
        $this->assertSame('audit', $xml->getName());
        $this->assertSame(['123456789', 'RF0000001', '1', 'shop.example', '10', '2026'], [(string) $xml->eik, (string) $xml->e_shop_n, (string) $xml->e_shop_type, (string) $xml->domain_name, (string) $xml->mon, (string) $xml->god]);
        $this->assertCount(2, $xml->order->orderenum);

        $first = $xml->order->orderenum[0];
        $this->assertSame(['ORD-1', '0000000001', '25.00', '5.00', '30.00', '4', 'pi_ORD-1', 'IE3206651KH Stripe Payments Europe Ltd.'], [(string) $first->ord_n, (string) $first->doc_n, (string) $first->ord_total1, (string) $first->ord_vat, (string) $first->ord_total2, (string) $first->paym, (string) $first->trans_n, (string) $first->proc_id]);
        $this->assertCount(2, $first->art->artenum);
        $this->assertSame('3', (string) $xml->order->orderenum[1]->paym);

        $this->assertSame('1', (string) $xml->r_ord);
        $this->assertSame(['ORD-1', '6.00', '2'], [(string) $xml->rorder->rorderenum[0]->r_ord_n, (string) $xml->rorder->rorderenum[0]->r_amount, (string) $xml->rorder->rorderenum[0]->r_paym]);
        $this->assertSame('6.00', (string) $xml->r_total);
    }
}
