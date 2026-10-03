<?php

namespace Tests\Feature\Admin;

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PnShop\Acl\Models\AdminUser;
use PnShop\Api\StaffTokens;
use PnShop\Catalog\Filament\Resources\PriceLists\Pages\CreatePriceList;
use PnShop\Catalog\Filament\Resources\PriceLists\Pages\EditPriceList;
use PnShop\Catalog\Filament\Resources\PriceLists\PriceListResource;
use PnShop\Catalog\Filament\Resources\PriceLists\RelationManagers\EntriesRelationManager;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Pricing\Models\PriceList;
use PnShop\Catalog\Pricing\PriceListEntries;
use PnShop\Customer\Models\CustomerGroup;

class PriceListAdminTest extends AdminTestCase
{
    private function product(string $sku, string $price): Product
    {
        $product = Product::factory()->active()->create(['price' => $price, 'sale_price' => null]);
        $product->defaultVariant()->update(['sku' => $sku]);

        return $product;
    }

    public function test_staff_create_a_price_list_and_import_and_export_its_prices(): void
    {
        $this->actingAsStaff(['catalog.prices.manage']);
        $group = CustomerGroup::query()->create(['code' => 'wholesale', 'name' => 'Wholesale']);
        $this->product('LAMP-1', '100.00');
        $this->product('MUG-1', '12.00');

        Livewire::test(CreatePriceList::class)
            ->fillForm(['name' => 'Wholesale', 'customer_group_id' => $group->id, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $list = PriceList::query()->sole();
        $this->assertSame('USD', $list->currency, 'Lists use the default currency.');

        $csv = "sku,min_quantity,price\nLAMP-1,1,80\nLAMP-1,10,75.50\nMUG-1,,9\nNOPE,1,5\nMUG-1,1,abc\n";
        Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $list, 'pageClass' => EditPriceList::class])
            ->callTableAction('import', data: ['file' => UploadedFile::fake()->createWithContent('prices.csv', $csv)])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertSame(3, $list->entries()->count());

        $export = app(PriceListEntries::class)->exportCsv($list);
        $this->assertStringContainsString('LAMP-1,10,75.50', $export);
        $this->assertStringContainsString('MUG-1,1,9.00', $export);

        // An empty price removes it.
        $result = app(PriceListEntries::class)->importCsv($list, "sku,min_quantity,price\nLAMP-1,10,\n");
        $this->assertSame(1, $result['removed']);
        $this->assertSame(2, $list->entries()->count());
    }

    public function test_the_import_reports_rows_it_cannot_use(): void
    {
        $this->product('LAMP-1', '100.00');
        $list = PriceList::query()->create(['name' => 'Volume']);

        $result = app(PriceListEntries::class)->importCsv($list, "sku,min_quantity,price\nNOPE,1,5\nLAMP-1,0,5\nLAMP-1,1,-3\nLAMP-1,1,4.99\n");

        $this->assertSame(1, $result['saved']);
        $this->assertCount(3, $result['errors']);
        $this->assertStringContainsString('NOPE', $result['errors'][0]);
    }

    public function test_only_staff_with_the_permission_manage_price_lists(): void
    {
        $this->actingAsStaff(['catalog.products.update']);

        $this->get(PriceListResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_admin_api_manages_price_lists_by_sku(): void
    {
        $this->product('LAMP-1', '100.00');
        $headers = ['Authorization' => 'Bearer '.app(StaffTokens::class)->issue(AdminUser::factory()->withPermissions(['catalog.prices.manage'])->create(), 'erp', ['catalog.prices.manage'])->plainTextToken];

        $id = $this->withHeaders($headers)->postJson('/api/admin/v1/price-lists', ['name' => 'Volume'])
            ->assertCreated()
            ->assertJsonPath('data.customer_group_id', null)
            ->json('data.id');

        $this->withHeaders($headers)->putJson("/api/admin/v1/price-lists/{$id}/prices", ['prices' => [
            ['sku' => 'LAMP-1', 'min_quantity' => 10, 'price' => '90.00'],
            ['sku' => 'MISSING', 'price' => '1.00'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.saved', 1)
            ->assertJsonCount(1, 'data.errors');

        $this->withHeaders($headers)->getJson("/api/admin/v1/price-lists/{$id}/prices")
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'LAMP-1')
            ->assertJsonPath('data.0.price.amount', '90.00');

        $other = ['Authorization' => 'Bearer '.app(StaffTokens::class)->issue(AdminUser::factory()->withPermissions(['catalog.products.update'])->create(), 'x', ['catalog.products.update'])->plainTextToken];
        $this->withHeaders($other)->getJson('/api/admin/v1/price-lists')->assertForbidden();
    }
}
