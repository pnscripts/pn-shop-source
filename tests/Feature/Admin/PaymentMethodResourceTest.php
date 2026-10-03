<?php

namespace Tests\Feature\Admin;

use Livewire\Livewire;
use PnShop\Customer\Models\CustomerGroup;
use PnShop\Payment\Filament\RelationManagers\PaymentsRelationManager;
use PnShop\Payment\Filament\Resources\PaymentMethods\Pages\CreatePaymentMethod;
use PnShop\Payment\Filament\Resources\PaymentMethods\Pages\EditPaymentMethod;
use PnShop\Payment\Filament\Resources\PaymentMethods\Pages\ListPaymentMethods;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Sales\Filament\Resources\Orders\Pages\ViewOrder;
use PnShop\Sales\Models\Order;

class PaymentMethodResourceTest extends AdminTestCase
{
    public function test_a_bank_transfer_method_is_created_with_its_gateway_settings(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(CreatePaymentMethod::class)
            ->fillForm(['name' => 'Bank transfer', 'gateway' => 'bank_transfer'])
            ->assertSchemaComponentExists('settings.iban', 'form')
            ->fillForm([
                'settings' => ['iban' => 'BG80BNBG96611020345678', 'account_holder' => 'PN Demo Ltd'],
                'min_total' => '10',
                'countries' => ['BG'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $method = PaymentMethod::query()->sole();
        $this->assertSame('BG80BNBG96611020345678', $method->setting('iban'));
        $this->assertStringContainsString(':amount', (string) $method->setting('instructions'));
        $this->assertSame('10.00', (string) $method->min_total?->getAmount());
        $this->assertSame(['BG'], $method->countries);
    }

    public function test_methods_are_listed_and_editable(): void
    {
        $this->actingAsAdministrator();
        $method = PaymentMethod::factory()->create(['gateway' => 'cash_on_delivery', 'min_total' => '5.00']);
        $missing = PaymentMethod::factory()->create(['gateway' => 'stripe']);

        Livewire::test(ListPaymentMethods::class)
            ->assertCanSeeTableRecords([$method, $missing])
            ->assertSee('stripe (not installed)');

        Livewire::test(EditPaymentMethod::class, ['record' => $method->getRouteKey()])
            ->assertSchemaStateSet(['min_total' => '5.00'])
            ->fillForm(['name' => 'Pay on delivery'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Pay on delivery', $method->fresh()->name);
    }

    public function test_staff_without_permission_cannot_manage_methods(): void
    {
        $this->actingAsStaff(['sales.orders.view']);

        $this->get(ListPaymentMethods::getUrl())->assertForbidden();
    }

    public function test_order_payments_are_shown(): void
    {
        $this->actingAsAdministrator();
        $order = Order::factory()->create(['total' => '12.00', 'currency' => 'USD']);
        $order->payments()->create(['gateway' => 'bank_transfer', 'currency' => 'USD', 'amount' => '12.00', 'reference' => 'REF-1']);

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertSee('REF-1')
            ->assertSee('$12.00')
            ->assertSee('Pending');
    }

    public function test_an_invoice_method_is_limited_to_customer_groups(): void
    {
        $this->actingAsAdministrator();
        $wholesale = CustomerGroup::query()->create(['code' => 'wholesale', 'name' => 'Wholesale']);

        Livewire::test(CreatePaymentMethod::class)
            ->fillForm(['name' => 'Invoice', 'gateway' => 'invoice'])
            ->assertSchemaComponentExists('settings.terms_days', 'form')
            ->fillForm(['customer_group_ids' => [(string) $wholesale->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $method = PaymentMethod::query()->sole();
        $this->assertSame([$wholesale->id], $method->customer_group_ids);
        $this->assertSame(30, $method->setting('terms_days'));
    }
}
