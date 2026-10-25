<?php

namespace Tests\Feature\Extensions;

use Illuminate\Support\Facades\Artisan;
use PnShop\Extension\Manifest;
use PnShop\Plugins\Withdrawal\Models\Withdrawal;
use Tests\Feature\Admin\AdminTestCase;

/**
 * The admin screens of the shipped plugins, with the plugins enabled when the application
 * boots (as in a shop), so their resources are on the panel in strict authorization mode.
 */
class PluginAdminScreensTest extends AdminTestCase
{
    private const PLUGINS = ['pnshop/withdrawal', 'pnshop/bg-euro', 'pnshop/bg-n18'];

    private static string $cache;

    protected function setUp(): void
    {
        // Written before the application boots, as `pnshop:plugin enable` leaves it in a shop.
        self::$cache = sys_get_temp_dir().'/pnshop-plugins-boot-'.bin2hex(random_bytes(4)).'.php';
        $plugins = [];

        foreach (self::PLUGINS as $id) {
            $path = dirname(__DIR__, 3).'/extensions/'.$id;
            $plugins[$id] = ['path' => $path, 'autoload' => Manifest::fromDirectory($path)->autoload, 'manifest' => json_decode((string) file_get_contents($path.'/pnshop.json'), true)];
        }

        file_put_contents(self::$cache, '<?php return '.var_export(['plugins' => $plugins], true).';'.PHP_EOL);
        putenv('PNSHOP_PLUGIN_CACHE='.self::$cache);
        $_ENV['PNSHOP_PLUGIN_CACHE'] = $_SERVER['PNSHOP_PLUGIN_CACHE'] = self::$cache;

        parent::setUp();

        foreach (self::PLUGINS as $id) {
            Artisan::call('migrate', ['--path' => 'extensions/'.$id.'/database/migrations', '--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        @unlink(self::$cache);
        putenv('PNSHOP_PLUGIN_CACHE');
        unset($_ENV['PNSHOP_PLUGIN_CACHE'], $_SERVER['PNSHOP_PLUGIN_CACHE']);
    }

    private function withdrawal(): Withdrawal
    {
        $withdrawal = new Withdrawal;
        $withdrawal->forceFill(['reference' => 'W-TEST0001', 'order_number' => 'ORD-1', 'name' => 'Ana', 'email' => 'ana@example.com', 'submitted_at' => now()])->save();

        return $withdrawal;
    }

    public function test_staff_without_the_permission_cannot_open_withdrawals(): void
    {
        $this->withdrawal();
        $this->actingAsStaff(['catalog.products.view']);

        $this->get('/admin/withdrawals')->assertForbidden();
    }

    public function test_staff_who_handle_withdrawals_see_them(): void
    {
        $this->withdrawal();
        $this->actingAsStaff(['withdrawal.manage']);

        $this->get('/admin/withdrawals')->assertOk()->assertSee('W-TEST0001')->assertSee('No matching order');
    }

    public function test_administrators_see_withdrawals(): void
    {
        $this->withdrawal();
        $this->actingAsAdministrator();

        $this->get('/admin/withdrawals')->assertOk()->assertSee('W-TEST0001');
    }

    public function test_the_euro_check_is_on_the_dashboard_and_on_the_command_line(): void
    {
        $this->actingAsStaff(['store.localization.manage']);
        $this->get('/admin')->assertOk()->assertSee('Euro check (Bulgaria)');

        // Tests run in USD: the check fails until the shop sells in euro.
        $this->artisan('pnshop:euro-check')->assertFailed();
    }

    public function test_the_euro_check_is_hidden_from_other_staff(): void
    {
        $this->actingAsStaff(['catalog.products.view']);
        $this->get('/admin')->assertOk()->assertDontSee('Euro check (Bulgaria)');
    }

    public function test_sales_documents_are_listed_for_staff_with_the_permission_only(): void
    {
        $this->actingAsStaff(['sales.orders.view']);
        $this->get('/admin/sales-documents')->assertForbidden();
    }

    public function test_staff_with_the_permission_see_the_sales_documents(): void
    {
        $this->actingAsStaff(['n18.manage']);
        $this->get('/admin/sales-documents')->assertOk()->assertSee('NRA audit file');
    }
}
