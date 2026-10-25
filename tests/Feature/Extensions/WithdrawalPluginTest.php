<?php

namespace Tests\Feature\Extensions;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PnShop\Plugins\Withdrawal\Filament\Resources\Withdrawals\WithdrawalResource;
use PnShop\Plugins\Withdrawal\Models\Withdrawal;
use PnShop\Plugins\Withdrawal\Notifications\WithdrawalForStaff;
use PnShop\Plugins\Withdrawal\Notifications\WithdrawalReceived;
use PnShop\Plugins\Withdrawal\WithdrawalStatements;
use PnShop\Sales\Models\Order;
use PnShop\Security\BotTrap;
use PnShop\Settings\Settings;

/**
 * The withdrawal function plugin (extensions/pnshop/withdrawal).
 */
class WithdrawalPluginTest extends ShippedPluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enablePlugin('pnshop/withdrawal');
        app(Settings::class)->set('store', ['email' => 'shop@example.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function statement(array $overrides = []): array
    {
        return ['name' => 'Ana Petrova', 'email' => 'ana@example.com', 'order_number' => 'ORD-000042', 'items' => '', ...BotTrap::fields(now()->subMinute()), ...$overrides];
    }

    public function test_a_guest_withdraws_in_two_steps_and_gets_a_dated_confirmation(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-25 09:15:30');
        $order = Order::factory()->create(['email' => 'Ana@Example.com']);
        $order->forceFill(['number' => 'ORD-000042'])->saveQuietly();

        // The link is offered on every page; the order page's link fills in the number.
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('withdrawal.url', route('withdrawal.create')));
        $this->get(route('withdrawal.create', ['order' => 'ORD-000042']))->assertInertia(fn (Assert $page) => $page
            ->component('extension')
            ->where('screen', 'withdrawal')
            ->where('data.step', 'details')
            ->where('data.values.order_number', 'ORD-000042'));

        // Step 1 only reviews: nothing is sent yet.
        $this->post(route('withdrawal.review'), $this->statement())->assertRedirect(route('withdrawal.confirm'));
        $this->assertSame(0, Withdrawal::query()->count());
        $this->get(route('withdrawal.confirm'))->assertInertia(fn (Assert $page) => $page
            ->where('data.step', 'confirm')
            ->where('data.statement.name', 'Ana Petrova'));

        // Step 2 sends it.
        $this->post(route('withdrawal.store'))->assertRedirect(route('withdrawal.sent'));
        $withdrawal = Withdrawal::query()->sole();
        $this->assertSame($order->id, $withdrawal->order_id);
        $this->assertSame('2026-10-25 09:15:30', $withdrawal->submitted_at->toDateTimeString());
        $this->get(route('withdrawal.sent'))->assertInertia(fn (Assert $page) => $page->where('data.sent.reference', $withdrawal->reference));

        Notification::assertSentTo(new AnonymousNotifiable, WithdrawalReceived::class, function (WithdrawalReceived $notification, array $channels, AnonymousNotifiable $notifiable) {
            $mail = $notification->toMail($notifiable);
            $text = implode("\n", $mail->introLines);

            return $notifiable->routes['mail'] === 'ana@example.com'
                && str_contains($text, '25.10.2026')
                && str_contains($text, '09:15:30')
                && str_contains($text, 'ORD-000042');
        });
        Notification::assertSentTo(new AnonymousNotifiable, WithdrawalForStaff::class, fn ($n, $c, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'shop@example.com');

        // Sending twice needs a new statement.
        $this->post(route('withdrawal.store'))->assertRedirect(route('withdrawal.create'));
        $this->assertSame(1, Withdrawal::query()->count());
    }

    public function test_statements_that_match_no_order_are_still_accepted(): void
    {
        Notification::fake();

        $this->post(route('withdrawal.review'), $this->statement(['order_number' => 'UNKNOWN-1']));
        $this->post(route('withdrawal.store'));

        $withdrawal = Withdrawal::query()->sole();
        $this->assertNull($withdrawal->order_id);
        $this->assertNull(app(WithdrawalStatements::class)->looksLate($withdrawal));
    }

    public function test_the_statement_is_validated_and_bots_are_refused(): void
    {
        $this->post(route('withdrawal.review'), $this->statement(['email' => 'not-an-email', 'name' => '']))->assertSessionHasErrors(['email', 'name']);
        $this->post(route('withdrawal.review'), $this->statement([BotTrap::HONEYPOT => 'spam']))->assertSessionHasErrors('form');
        $this->get(route('withdrawal.confirm'))->assertRedirect(route('withdrawal.create'));
    }

    public function test_late_statements_are_marked_for_staff(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-09-01 10:00');
        $order = Order::factory()->create(['email' => 'ana@example.com']);
        $order->forceFill(['number' => 'ORD-000042'])->saveQuietly();

        Carbon::setTestNow('2026-09-30 10:00');
        $this->post(route('withdrawal.review'), $this->statement());
        $this->post(route('withdrawal.store'));

        $this->assertTrue(app(WithdrawalStatements::class)->looksLate(Withdrawal::query()->sole()));
    }

    public function test_the_bulgarian_labels_are_the_statutory_ones(): void
    {
        $this->get('/bg/withdrawal')->assertInertia(fn (Assert $page) => $page
            ->where('title', 'Отказ от договора')
            ->where('translations', fn ($translations) => collect($translations)->get('Withdraw from contract here') === 'Откажете се от договора тук'
                && collect($translations)->get('Confirm withdrawal') === 'Потвърждавам отказа'));
    }

    public function test_staff_see_and_handle_statements_but_cannot_edit_or_delete_them(): void
    {
        Notification::fake();
        $this->post(route('withdrawal.review'), $this->statement());
        $this->post(route('withdrawal.store'));
        $withdrawal = Withdrawal::query()->sole();

        $this->assertScreensHavePolicies('pnshop/withdrawal', 'PnShop\\Plugins\\Withdrawal');
        $this->assertSame(Withdrawal::class, WithdrawalResource::getModel());

        $other = $this->actingAsStaff(['catalog.products.view']);
        $this->assertFalse($other->can('viewAny', Withdrawal::class));

        $admin = $this->actingAsStaff(['withdrawal.manage']);
        $this->assertTrue($admin->can('viewAny', Withdrawal::class));
        $this->assertTrue($admin->can('update', $withdrawal));
        $this->assertFalse($admin->can('delete', $withdrawal));
        $this->assertFalse($admin->can('create', Withdrawal::class));

        // Mass assignment cannot rewrite the customer's statement.
        $withdrawal->update(['name' => 'Someone else', 'staff_note' => 'Return label sent']);
        $this->assertSame('Ana Petrova', $withdrawal->refresh()->name);
        $this->assertSame('Return label sent', $withdrawal->staff_note);
    }
}
