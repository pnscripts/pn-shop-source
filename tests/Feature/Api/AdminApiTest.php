<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PnShop\Acl\Models\AdminUser;
use PnShop\Api\StaffTokens;
use PnShop\Catalog\Models\Category;
use PnShop\Catalog\Models\Option;
use PnShop\Catalog\Models\Product;
use PnShop\Cms\Blocks\Types\HtmlBlock;
use PnShop\Cms\Models\Page;
use PnShop\Returns\ReturnReason;
use PnShop\Returns\ReturnService;
use PnShop\Sales\Models\Order;
use PnShop\Sales\States\OrderStatus;
use PnShop\Settings\Settings;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/admin/v1';

    /**
     * @param  list<string>  $abilities
     * @param  list<string>  $permissions  given to a non-administrator owner
     * @return array{Authorization: string}
     */
    private function token(array $abilities = ['*'], ?array $permissions = null): array
    {
        $admin = $permissions === null
            ? AdminUser::factory()->administrator()->create()
            : AdminUser::factory()->withPermissions($permissions)->create();

        return ['Authorization' => 'Bearer '.app(StaffTokens::class)->issue($admin, 'test', $abilities)->plainTextToken];
    }

    public function test_requires_an_active_staff_token(): void
    {
        $this->getJson(self::API.'/products')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

        // Without a token, records are not even looked up.
        $this->getJson(self::API.'/products/999')->assertUnauthorized();

        $customer = User::factory()->create()->createToken('app', ['store'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$customer)->getJson(self::API.'/products')->assertUnauthorized();

        $this->flushHeaders();
        $admin = AdminUser::factory()->administrator()->inactive()->create();
        $this->withHeader('Authorization', 'Bearer '.$admin->createToken('x', ['*'])->plainTextToken)->getJson(self::API.'/me')->assertUnauthorized();
    }

    public function test_abilities_limit_even_an_administrator_token(): void
    {
        $headers = $this->token(['catalog.products.view']);

        $this->withHeaders($headers)->getJson(self::API.'/products')->assertOk();
        $this->withHeaders($headers)->getJson(self::API.'/orders')->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->withHeaders($headers)->postJson(self::API.'/products', ['title' => 'X'])->assertForbidden();
        $this->withHeaders($headers)->getJson(self::API.'/me')->assertOk()->assertJsonPath('data.token.abilities', ['catalog.products.view']);
    }

    public function test_a_token_never_exceeds_its_owner(): void
    {
        $owner = AdminUser::factory()->withPermissions(['catalog.products.view'])->create();

        try {
            app(StaffTokens::class)->issue($owner, 'too much', ['sales.orders.view']);
            $this->fail('A token with a permission the owner lacks was issued.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('abilities', $e->errors());
        }

        // "*" means "whatever the owner may do", not everything.
        $headers = $this->token(['*'], ['catalog.products.view']);
        $this->withHeaders($headers)->getJson(self::API.'/products')->assertOk();
        $this->withHeaders($headers)->getJson(self::API.'/orders')->assertForbidden();
    }

    public function test_create_and_update_a_simple_product_with_translations(): void
    {
        $headers = $this->token();
        $category = Category::factory()->create();

        $id = $this->withHeaders($headers)->postJson(self::API.'/products', [
            'title' => 'Desk Lamp',
            'category_id' => $category->id,
            'price' => '49.90',
            'sku' => 'LAMP-1',
            'stock' => 7,
            'is_active' => true,
            'translations' => ['bg' => ['title' => 'Настолна лампа']],
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'desk-lamp')
            ->assertJsonPath('data.variants.0.price.amount', '49.90')
            ->assertJsonPath('data.variants.0.on_hand', 7)
            ->assertJsonPath('data.translations.bg.title', 'Настолна лампа')
            ->json('data.id');

        $this->withHeaders($headers)->patchJson(self::API."/products/{$id}", ['sale_price' => '39.90', 'translations' => ['bg' => ['description' => 'Ярка']]])
            ->assertOk()
            ->assertJsonPath('data.variants.0.sale_price.amount', '39.90')
            ->assertJsonPath('data.translations.bg.title', 'Настолна лампа')
            ->assertJsonPath('data.translations.bg.description', 'Ярка');

        $this->withHeaders($headers)->postJson(self::API.'/products', ['title' => 'X', 'category_id' => $category->id, 'price' => 1, 'translations' => ['xx' => ['title' => 'Y']]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->withHeaders($headers)->getJson(self::API.'/products?filter[sku]=LAMP-1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_variants_need_one_value_per_option_and_sync_stock_by_sku(): void
    {
        $headers = $this->token();
        $size = Option::query()->create(['code' => 'size', 'name' => 'Size']);
        [$small, $large] = [$size->values()->create(['value' => 'S']), $size->values()->create(['value' => 'L'])];

        $product = $this->withHeaders($headers)->postJson(self::API.'/products', [
            'type' => 'variable', 'title' => 'Tee', 'category_id' => Category::factory()->create()->id, 'option_ids' => [$size->id],
        ])->assertCreated()->json('data');

        $this->withHeaders($headers)->postJson(self::API."/products/{$product['id']}/variants", ['price' => 20, 'sku' => 'TEE-S', 'option_value_ids' => [$small->id], 'stock' => 3])
            ->assertCreated()
            ->assertJsonPath('data.option_value_ids', [$small->id]);

        $this->withHeaders($headers)->postJson(self::API."/products/{$product['id']}/variants", ['price' => 20, 'option_value_ids' => [$small->id]])
            ->assertStatus(422);
        $this->withHeaders($headers)->postJson(self::API."/products/{$product['id']}/variants", ['price' => 20, 'option_value_ids' => []])
            ->assertStatus(422);
        $this->withHeaders($headers)->postJson(self::API."/products/{$product['id']}/variants", ['price' => 22, 'sku' => 'TEE-L', 'option_value_ids' => [$large->id]])
            ->assertCreated();

        // ERP sync: find by SKU, set price and stock.
        $variant = $this->withHeaders($headers)->getJson(self::API.'/variants?filter[sku]=TEE-S')->assertOk()->json('data.0.id');
        $this->withHeaders($headers)->patchJson(self::API."/variants/{$variant}", ['price' => '18.50'])->assertOk()->assertJsonPath('data.price.amount', '18.50');
        $this->withHeaders($headers)->postJson(self::API."/variants/{$variant}/stock", ['adjust' => -1, 'note' => 'Damaged'])->assertOk()->assertJsonPath('data.on_hand', 2);
        $this->withHeaders($headers)->postJson(self::API."/variants/{$variant}/stock", ['on_hand' => 10])->assertOk()->assertJsonPath('data.on_hand', 10);

        $this->assertDatabaseHas('stock_movements', ['product_variant_id' => $variant, 'quantity' => -1, 'note' => 'Damaged']);
    }

    public function test_a_simple_product_keeps_one_variant(): void
    {
        $product = Product::factory()->create();

        $this->withHeaders($this->token())->postJson(self::API."/products/{$product->id}/variants", ['price' => 5])->assertStatus(422);
    }

    public function test_stock_needs_the_inventory_permission(): void
    {
        $product = Product::factory()->active()->create(['stock' => 5]);
        $headers = $this->token(['catalog.products.update']);

        $this->withHeaders($headers)->postJson(self::API.'/variants/'.$product->defaultVariant()->id.'/stock', ['on_hand' => 1])->assertForbidden();

        // The stock fields of the product and variant endpoints need it too.
        $this->withHeaders($headers)->patchJson(self::API."/products/{$product->id}", ['stock' => 99])->assertForbidden();
        $this->withHeaders($headers)->patchJson(self::API.'/variants/'.$product->defaultVariant()->id, ['stock' => 99])->assertForbidden();
        $this->withHeaders($headers)->patchJson(self::API."/products/{$product->id}", ['price' => '9.50'])->assertOk();

        $this->assertNotSame(99, (int) $product->defaultVariant()->stockLevels()->sum('on_hand'));
    }

    public function test_order_transitions_go_through_the_workflow(): void
    {
        $headers = $this->token();
        $order = Order::factory()->create();

        $this->withHeaders($headers)->getJson(self::API."/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => ['transitions' => ['status', 'payment_status', 'fulfillment_status'], 'history', 'payments']]);

        $this->withHeaders($headers)->postJson(self::API."/orders/{$order->id}/transitions", ['field' => 'status', 'to' => 'processing', 'note' => 'Checked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');

        $this->assertSame(OrderStatus::Processing, $order->refresh()->status);
        $this->assertDatabaseHas('order_history', ['order_id' => $order->id, 'field' => 'status', 'to' => 'processing', 'note' => 'Checked', 'actor_type' => 'admin_user']);

        $this->withHeaders($headers)->postJson(self::API."/orders/{$order->id}/transitions", ['field' => 'status', 'to' => 'pending'])
            ->assertStatus(422);
        $this->withHeaders($headers)->postJson(self::API."/orders/{$order->id}/transitions", ['field' => 'status', 'to' => 'nonsense'])
            ->assertStatus(422);
    }

    public function test_orders_list_with_filters_and_updated_since(): void
    {
        $headers = $this->token();
        Order::factory()->create(['email' => 'a@example.com']);
        Order::factory()->create(['email' => 'b@example.com']);

        $this->withHeaders($headers)->getJson(self::API.'/orders?filter[email]=b@example.com')->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders($headers)->getJson(self::API.'/orders?filter[updated_since]='.urlencode(now()->addDay()->toIso8601String()))->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($headers)->getJson(self::API.'/orders?filter[updated_since]=not-a-date')->assertStatus(422)->assertJsonStructure(['errors' => ['filter.updated_since']]);
        $this->withHeaders($headers)->getJson(self::API.'/orders?filter[updated_since][]=x')->assertStatus(422);
    }

    public function test_pages_with_blocks_and_the_html_block_lock(): void
    {
        $headers = $this->token(['content.pages.manage'], ['content.pages.manage']);

        $id = $this->withHeaders($headers)->postJson(self::API.'/pages', [
            'title' => 'About',
            'status' => 'published',
            'blocks' => ['en' => [['type' => 'rich_text', 'data' => ['content' => '<p>Hello</p>']]]],
        ])->assertCreated()->assertJsonPath('data.blocks.en.0.type', 'rich_text')->json('data.id');

        $this->assertSame(1, Page::query()->findOrFail($id)->revisions()->count());

        $this->withHeaders($headers)->patchJson(self::API."/pages/{$id}", [
            'blocks' => ['en' => [['type' => (new HtmlBlock)->key(), 'data' => ['html' => '<script>x</script>']]]],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['blocks.en']]);

        $this->withHeaders($headers)->patchJson(self::API."/pages/{$id}", ['blocks' => ['en' => [['type' => 'nope']]]])->assertStatus(422);
    }

    public function test_settings_hide_secrets_and_validate(): void
    {
        $headers = $this->token();

        $this->withHeaders($headers)->patchJson(self::API.'/settings/store', ['values' => ['name' => 'Acme']])
            ->assertOk()
            ->assertJsonPath('data.fields.0.value', 'Acme');

        $this->assertSame('Acme', app(Settings::class)->get('store.name'));

        $this->withHeaders($headers)->patchJson(self::API.'/settings/store', ['values' => ['email' => 'not-an-email']])->assertStatus(422);
        $this->withHeaders($headers)->patchJson(self::API.'/settings/store', ['values' => ['unknown' => 1]])->assertStatus(422);
        $this->withHeaders($headers)->getJson(self::API.'/settings/appearance')->assertNotFound();
        $this->withHeaders($headers)->getJson(self::API.'/settings')->assertOk()->assertJsonFragment(['namespace' => 'seo']);
    }

    public function test_media_upload_and_system_listings(): void
    {
        Storage::fake('public');
        $headers = $this->token();

        $media = $this->withHeaders($headers)->post(self::API.'/media', ['file' => UploadedFile::fake()->image('photo.jpg', 40, 30), 'alt' => 'A photo'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.width', 40)
            ->json('data.id');

        $this->withHeaders($headers)->post(self::API.'/media', ['file' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')], ['Accept' => 'application/json'])->assertStatus(422);

        $product = Product::factory()->create();
        $this->withHeaders($headers)->patchJson(self::API."/products/{$product->id}", ['gallery' => [$media]])->assertOk()->assertJsonPath('data.gallery.0.id', $media);

        $this->withHeaders($headers)->getJson(self::API.'/extensions')->assertOk()->assertJsonStructure(['data', 'meta' => ['pnshop_version']]);
        $this->withHeaders($headers)->getJson(self::API.'/themes')->assertOk()->assertJsonFragment(['id' => 'pnshop/default', 'active' => true]);
    }

    public function test_api_token_command(): void
    {
        AdminUser::factory()->administrator()->create(['email' => 'ops@example.com']);

        $this->artisan('pnshop:api-token', ['email' => 'ops@example.com', '--ability' => ['sales.orders.view'], '--days' => 30])
            ->expectsOutputToContain('shown only once')
            ->assertSuccessful();

        $this->artisan('pnshop:api-token', ['email' => 'ops@example.com', '--ability' => ['no.such.permission']])->assertFailed();
        $this->artisan('pnshop:api-token', ['email' => 'nobody@example.com', '--all' => true])->assertFailed();
    }

    public function test_promotions_are_validated_against_the_registered_types(): void
    {
        $headers = $this->token(['marketing.promotions.manage'], ['marketing.promotions.manage']);

        $this->withHeaders($headers)->postJson(self::API.'/promotions', ['name' => 'Bad', 'actions' => [['type' => 'percent_off', 'data' => ['percent' => 500]]]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['actions.0.data.percent']]);

        $this->withHeaders($headers)->postJson(self::API.'/promotions', ['name' => 'Bad', 'actions' => [['type' => 'teleport']]])->assertStatus(422);

        $id = $this->withHeaders($headers)->postJson(self::API.'/promotions', [
            'name' => 'Newsletter',
            'requires_coupon' => true,
            'conditions' => [['type' => 'subtotal', 'data' => ['min' => 30]]],
            'actions' => [['type' => 'fixed_off', 'data' => ['amount' => '5']]],
        ])->assertCreated()->assertJsonPath('data.actions.0.type', 'fixed_off')->json('data.id');

        $codes = $this->withHeaders($headers)->postJson(self::API."/promotions/{$id}/coupons", ['count' => 3, 'prefix' => 'NL', 'usage_limit' => 1])
            ->assertCreated()
            ->json('data.codes');

        $this->assertCount(3, $codes);
        $this->withHeaders($headers)->getJson(self::API."/promotions/{$id}")->assertOk()->assertJsonCount(3, 'data.coupons');
        $this->withHeaders($headers)->patchJson(self::API."/promotions/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_returns_move_through_the_workflow(): void
    {
        $order = Order::factory()->create(['fulfillment_status' => 'fulfilled', 'payment_status' => 'paid', 'currency' => 'USD']);
        $item = $order->items()->create(['product_title' => 'Mug', 'quantity' => 2, 'quantity_fulfilled' => 2, 'currency' => 'USD', 'price' => '10.00']);
        $return = app(ReturnService::class)->request($order, [$item->id => 1], ReturnReason::Damaged);

        $headers = $this->token(['sales.returns.manage'], ['sales.returns.manage']);

        $this->withHeaders($headers)->getJson(self::API.'/returns?filter[status]=requested')->assertOk()->assertJsonPath('data.0.number', $return->number);
        $this->withHeaders($headers)->postJson(self::API."/returns/{$return->id}/transitions", ['action' => 'reject'])->assertStatus(422);
        $this->withHeaders($headers)->postJson(self::API."/returns/{$return->id}/transitions", ['action' => 'refund'])->assertStatus(422);
        $this->withHeaders($headers)->postJson(self::API."/returns/{$return->id}/transitions", ['action' => 'approve', 'note' => 'Use the prepaid label.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.transitions', ['received', 'closed']);
        $this->withHeaders($headers)->postJson(self::API."/returns/{$return->id}/transitions", ['action' => 'receive', 'restock' => false])
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity_received', 1);
    }
}
