<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Livewire\Livewire;
use PnShop\Customer\Filament\Resources\CustomerGroups\Pages\ManageCustomerGroups;
use PnShop\Customer\Filament\Resources\Customers\Pages\EditCustomer;
use PnShop\Customer\Filament\Resources\Customers\Pages\ListCustomers;
use PnShop\Customer\Models\CustomerGroup;

class CustomerAdminTest extends AdminTestCase
{
    public function test_customers_are_listed_and_moved_between_groups(): void
    {
        $this->actingAsAdministrator();
        $customer = User::factory()->create();
        $wholesale = CustomerGroup::query()->create(['code' => 'wholesale', 'name' => 'Wholesale']);

        Livewire::test(ListCustomers::class)->assertCanSeeTableRecords([$customer]);

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['customer_group_id' => $wholesale->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($wholesale->id, $customer->fresh()->customer_group_id);
    }

    public function test_the_default_group_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(ManageCustomerGroups::class)->assertTableActionHidden('delete', CustomerGroup::default());
    }

    public function test_customer_screens_follow_permissions(): void
    {
        $this->actingAsStaff(role: 'customer-manager');

        $this->get('/admin/customers')->assertOk();
        $this->get('/admin/products')->assertForbidden();
    }

    public function test_viewers_cannot_edit_customers(): void
    {
        $this->actingAsStaff(['customers.view']);

        $this->get('/admin/customers')->assertOk();
        $this->get('/admin/customers/'.User::factory()->create()->id.'/edit')->assertForbidden();
    }

    public function test_groups_get_business_options(): void
    {
        $this->actingAsAdministrator();
        $group = CustomerGroup::query()->create(['code' => 'wholesale', 'name' => 'Wholesale']);

        Livewire::test(ManageCustomerGroups::class)
            ->callTableAction('edit', $group, data: ['prices_include_tax' => '0', 'min_order_total' => '250'])
            ->assertHasNoTableActionErrors();

        $group->refresh();
        $this->assertFalse($group->prices_include_tax);
        $this->assertSame('250.00', (string) $group->min_order_total?->getAmount());

        Livewire::test(ManageCustomerGroups::class)
            ->callTableAction('edit', $group, data: ['prices_include_tax' => null, 'min_order_total' => null])
            ->assertHasNoTableActionErrors();

        $this->assertNull($group->refresh()->prices_include_tax);
        $this->assertNull($group->min_order_total);
    }
}
