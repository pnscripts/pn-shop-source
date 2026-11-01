<?php

namespace Tests\Feature\Catalog;

use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use PnShop\Catalog\Exceptions\InvalidVariant;
use PnShop\Acl\Models\AdminUser;
use PnShop\Catalog\Filament\Resources\Options\Pages\CreateOption;
use PnShop\Catalog\Filament\Resources\Products\Pages\EditProduct;
use PnShop\Catalog\Filament\Resources\Products\RelationManagers\StockHistoryRelationManager;
use PnShop\Catalog\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use PnShop\Catalog\Filament\Widgets\LowStockProducts;
use PnShop\Catalog\Models\Option;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\ProductType;
use PnShop\Catalog\VariantService;
use PnShop\Inventory\Exceptions\InsufficientStock;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\Models\StockMovement;
use PnShop\Inventory\OrderStockStatus;
use PnShop\Inventory\StockMovementReason;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Sales\Models\Order;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\FulfillmentStatus;
use PnShop\Sales\States\OrderStatus;
use PnShop\Sales\States\PaymentStatus;
use Tests\Feature\Admin\AdminTestCase;

class VariantsAndInventoryTest extends AdminTestCase
{
    private PaymentMethod $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payment = PaymentMethod::factory()->create(['is_active' => true]);
    }

    public function test_simple_products_store_price_and_stock_on_their_default_variant(): void
    {
        $product = Product::factory()->active()->create(['price' => '20.00', 'sale_price' => '15.00', 'stock' => 4, 'sku' => 'LAMP-1']);

        $variant = $product->defaultVariant();
        $this->assertSame(1, $product->variants()->count());
        $this->assertSame('LAMP-1', $variant->sku);
        $this->assertSame('15.00', (string) $variant->unitPrice()->getAmount());
        $this->assertSame(4, $product->fresh()->stock);

        $movement = StockMovement::query()->where('product_variant_id', $variant->id)->sole();
        $this->assertSame(4, $movement->quantity);
        $this->assertSame(StockMovementReason::Adjustment, $movement->reason);
    }

    public function test_a_sale_price_above_the_price_is_never_charged(): void
    {
        $product = Product::factory()->active()->create(['price' => '10.00', 'stock' => 5]);
        $product->defaultVariant()->forceFill(['sale_price' => 1500])->saveQuietly();

        $this->post('/cart', ['product_id' => $product->id, 'quantity' => 1]);
        $this->get('/cart')->assertInertia(fn (Assert $page) => $page->where('cart.final_price.amount', '10.00'));

        $this->post('/checkout', $this->checkoutData($this->payment->id));
        $this->assertDatabaseHas('order_items', ['price' => 1000, 'sale_price' => null]);
    }

    public function test_the_product_page_offers_options_and_variants(): void
    {
        [$product, $small, $medium] = $this->tShirt();

        $this->get("/shop/{$product->slug}")->assertInertia(fn (Assert $page) => $page
            ->where('product.type', 'variable')
            ->where('product.options.0.name', 'Size')
            ->has('product.options.0.values', 2)
            ->has('product.variants', 2)
            ->where('product.default_variant_id', $small->id)
            ->where('product.variants.1.price.amount', '25.00')
            ->where('product.variants.1.stock', 1)
        );

        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.price_from', true)
            ->where('products.data.0.price.amount', '20.00')
            ->where('products.data.0.stock', 4)
        );
    }

    public function test_a_chosen_variant_is_reserved_at_checkout_and_taken_when_shipped(): void
    {
        [, , $medium] = $this->tShirt();

        $this->post('/cart', ['variant_id' => $medium->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->get('/cart')->assertInertia(fn (Assert $page) => $page
            ->where('cart.items.0.variant_label', 'Size: M')
            ->where('cart.final_price.amount', '25.00')
        );

        $this->post('/checkout', $this->checkoutData($this->payment->id))->assertRedirect();

        $order = Order::query()->sole();
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_variant_id' => $medium->id, 'variant_label' => 'Size: M', 'price' => 2500]);
        $this->assertSame(0, $medium->fresh()->available());
        $this->assertSame(['on_hand' => 1, 'reserved' => 1], $medium->stockLevels()->sole()->only(['on_hand', 'reserved']));
        $this->assertDatabaseMissing('stock_movements', ['product_variant_id' => $medium->id, 'reference_id' => $order->id]);

        app(OrderWorkflow::class)->transition($order, FulfillmentStatus::Fulfilled);

        $this->assertSame(['on_hand' => 0, 'reserved' => 0], $medium->stockLevels()->sole()->only(['on_hand', 'reserved']));
        $this->assertSame(OrderStockStatus::Fulfilled, $order->fresh()->stock_status);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $medium->id,
            'quantity' => -1,
            'reason' => 'order_fulfilled',
            'reference_type' => $order->getMorphClass(),
            'reference_id' => $order->id,
        ]);
    }

    public function test_a_product_with_variants_needs_a_variant_choice(): void
    {
        [$product] = $this->tShirt();

        $this->post('/cart', ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasErrors('variant_id');
    }

    public function test_stock_cannot_be_oversold(): void
    {
        [, , $medium] = $this->tShirt();

        $this->post('/cart', ['variant_id' => $medium->id, 'quantity' => 2])->assertSessionHasErrors('quantity');

        $this->expectException(InsufficientStock::class);
        app(InventoryService::class)->adjust($medium, -2, StockMovementReason::Order);
    }

    public function test_backorders_allow_selling_beyond_stock(): void
    {
        [, , $medium] = $this->tShirt();
        $medium->update(['allow_backorder' => true]);

        $this->post('/cart', ['variant_id' => $medium->id, 'quantity' => 3])->assertSessionHasNoErrors();
        $this->post('/checkout', $this->checkoutData($this->payment->id))->assertRedirect();
        app(OrderWorkflow::class)->transition(Order::query()->sole(), FulfillmentStatus::Fulfilled);

        $this->assertSame(-2, (int) $medium->stockLevels()->sum('on_hand'));
    }

    public function test_cancelling_an_open_order_releases_its_reservation(): void
    {
        [, , $medium] = $this->tShirt();
        $this->post('/cart', ['variant_id' => $medium->id, 'quantity' => 1]);
        $this->post('/checkout', $this->checkoutData($this->payment->id));

        app(OrderWorkflow::class)->transition(Order::query()->sole(), OrderStatus::Cancelled);

        $this->assertSame(1, $medium->fresh()->available());
        $this->assertSame(['on_hand' => 1, 'reserved' => 0], $medium->stockLevels()->sole()->only(['on_hand', 'reserved']));
        $this->assertSame(OrderStockStatus::Released, Order::query()->sole()->stock_status);
    }

    public function test_cancelling_a_shipped_order_puts_the_stock_back(): void
    {
        [, , $medium] = $this->tShirt();
        $this->post('/cart', ['variant_id' => $medium->id, 'quantity' => 1]);
        $this->post('/checkout', $this->checkoutData($this->payment->id));
        $order = Order::query()->sole();
        $workflow = app(OrderWorkflow::class);

        $workflow->transition($order, FulfillmentStatus::Fulfilled);
        $workflow->transition($order, PaymentStatus::Paid);
        $this->assertSame(0, $medium->fresh()->available());

        $workflow->transition($order, OrderStatus::Cancelled);

        $this->assertSame(['on_hand' => 1, 'reserved' => 0], $medium->stockLevels()->sole()->only(['on_hand', 'reserved']));
        $this->assertDatabaseHas('stock_movements', ['product_variant_id' => $medium->id, 'quantity' => 1, 'reason' => 'order_cancelled']);
    }

    public function test_variants_can_be_generated_and_edited_in_the_admin(): void
    {
        $this->actingAsAdministrator();
        $size = $this->option('size', 'Size', ['S', 'M', 'L']);
        $product = Product::factory()->active()->create(['type' => ProductType::Variable, 'price' => '10.00', 'sale_price' => null]);
        $product->options()->attach($size);

        $manager = fn () => Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product->fresh(), 'pageClass' => EditProduct::class]);

        $manager()->callTableAction('generate');

        $this->assertSame(4, $product->variants()->count(), 'The existing default variant plus one per size.');

        $large = ProductVariant::query()->whereHas('optionValues', fn ($query) => $query->where('value', 'L'))->sole();

        $manager()
            ->callTableAction('edit', $large, ['option_'.$size->id => $large->optionValues->first()->id, 'price' => '14.00', 'stock' => 6, 'is_active' => true, 'track_inventory' => true])
            ->assertHasNoTableActionErrors();

        $this->assertSame('14.00', (string) $large->fresh()->price->getAmount());
        $this->assertSame(6, $large->fresh()->available());
    }

    public function test_product_editors_without_inventory_rights_cannot_switch_stock_control_off(): void
    {
        $this->actingAs(AdminUser::factory()->withPermissions(['catalog.products.view', 'catalog.products.update'])->create(), 'admin');
        [$product, $small] = $this->tShirt();

        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product->fresh(), 'pageClass' => EditProduct::class])
            ->callTableAction('edit', $small, ['option_'.$product->options->first()->id => $small->optionValues->first()->id, 'price' => '31.00', 'is_active' => true, 'track_inventory' => false, 'allow_backorder' => true]);

        $this->assertSame('31.00', (string) $small->fresh()->price->getAmount());
        $this->assertTrue($small->fresh()->track_inventory);
        $this->assertFalse($small->fresh()->allow_backorder);
    }

    public function test_duplicate_option_combinations_are_rejected(): void
    {
        $this->actingAsAdministrator();
        [$product, $small] = $this->tShirt();

        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->callTableAction('create', data: ['option_'.$product->options->first()->id => $small->optionValues->first()->id, 'price' => '30.00', 'stock' => 1, 'is_active' => true]);

        $this->assertSame(2, $product->variants()->count());
    }

    public function test_the_admin_keeps_a_default_variant_and_never_deletes_the_last(): void
    {
        $this->actingAsAdministrator();
        [$product, $small] = $this->tShirt();
        $manager = fn () => Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product->fresh(), 'pageClass' => EditProduct::class]);
        $default = $product->variants()->where('is_default', true)->sole();
        $other = $product->variants()->whereKeyNot($default->id)->sole();

        $manager()->callTableAction('delete', $default);
        $this->assertTrue($other->fresh()->is_default, 'Deleting the default variant makes another one the default.');

        $manager()->callTableAction('delete', $other->fresh());
        $this->assertNotNull($other->fresh(), 'The last variant cannot be deleted.');
        $this->assertSame(1, $product->variants()->count());
    }

    public function test_generating_variants_is_capped(): void
    {
        $product = Product::factory()->active()->create(['type' => ProductType::Variable]);

        foreach (['A', 'B', 'C'] as $index => $name) {
            $product->options()->attach($this->option(strtolower($name), $name, array_map(fn (int $n) => "{$name}{$n}", range(1, 6))));
        }

        try {
            app(VariantService::class)->generate($product);
            $this->fail('6 × 6 × 6 variants were generated.');
        } catch (InvalidVariant) {
            $this->assertSame(1, $product->variants()->count());
        }
    }

    public function test_option_values_can_be_translated(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(CreateOption::class)
            ->fillForm([
                'name' => 'Color',
                'code' => 'color',
                'translations' => ['bg' => ['name' => 'Цвят']],
                'values' => [['value' => 'Red', 'translations_input' => ['bg' => ['value' => 'Червен']]]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $option = Option::query()->where('code', 'color')->sole();
        $this->assertSame('Цвят', $option->translation('name', 'bg'));
        $this->assertSame('Червен', $option->values->first()->translation('value', 'bg'));
    }

    public function test_the_low_stock_widget_lists_variants(): void
    {
        $this->actingAsAdministrator();
        [, $small, $medium] = $this->tShirt();

        Livewire::test(LowStockProducts::class)->assertCanSeeTableRecords([$small, $medium]);
    }

    public function test_a_variant_can_have_its_own_low_stock_threshold(): void
    {
        $this->actingAsAdministrator();
        [, $small, $medium] = $this->tShirt();

        // S has 3 left: low by default (5), not with a threshold of 2. M (1 left) stays low.
        $small->update(['low_stock_threshold' => 2]);

        Livewire::test(LowStockProducts::class)
            ->assertCanSeeTableRecords([$medium])
            ->assertCanNotSeeTableRecords([$small]);
    }

    public function test_the_product_shows_its_stock_history(): void
    {
        $this->actingAsAdministrator();
        [$product, $small] = $this->tShirt();
        app(InventoryService::class)->adjust($small, -1, StockMovementReason::Adjustment, null, null, 'Damaged in the store');

        Livewire::test(StockHistoryRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->assertCanSeeTableRecords($product->stockMovements()->get())
            ->assertSee('Damaged in the store');
    }

    /**
     * A T-shirt with Size S (20.00, 3 in stock, default) and M (25.00, 1 in stock).
     *
     * @return array{Product, ProductVariant, ProductVariant}
     */
    private function tShirt(): array
    {
        $size = $this->option('size', 'Size', ['S', 'M']);
        $product = Product::factory()->active()->create(['type' => ProductType::Variable, 'title' => 'T-shirt', 'price' => '20.00', 'sale_price' => null, 'stock' => 3]);
        $product->options()->attach($size);

        $small = $product->defaultVariant();
        $small->optionValues()->sync([$size->values[0]->id]);

        $medium = ProductVariant::query()->create(['product_id' => $product->id, 'price' => '25.00']);
        $medium->optionValues()->sync([$size->values[1]->id]);
        app(InventoryService::class)->setOnHand($medium, 1);

        return [$product->fresh(), $small->fresh(), $medium->fresh()];
    }

    /**
     * @param  list<string>  $values
     */
    private function option(string $code, string $name, array $values): Option
    {
        $option = Option::query()->create(['code' => $code, 'name' => $name]);

        foreach ($values as $position => $value) {
            $option->values()->create(['value' => $value, 'position' => $position]);
        }

        return $option->load('values');
    }
}
