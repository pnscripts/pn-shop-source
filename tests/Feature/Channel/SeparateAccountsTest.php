<?php

namespace Tests\Feature\Channel;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PnShop\Channel\Filament\Resources\Channels\Pages\CreateChannel;
use PnShop\Channel\Filament\Resources\Channels\Pages\EditChannel;
use PnShop\Channel\Models\Channel;
use PnShop\Customer\CustomerAccounts;
use PnShop\Security\BotTrap;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Customer accounts are shared by all channels, unless a channel is created with separate
 * customer accounts: then it has its own customers, and the same email address can have an
 * account there and one in the other channels.
 */
class SeparateAccountsTest extends AdminTestCase
{
    private Channel $trade;

    private Channel $club;

    private User $shared;

    protected function setUp(): void
    {
        parent::setUp();

        // A path channel and a domain channel with their own accounts; the main store shares.
        $this->trade = Channel::query()->create(['code' => 'trade', 'name' => 'Trade', 'path' => 'trade', 'separate_accounts' => true]);
        $this->club = Channel::query()->create(['code' => 'club', 'name' => 'Club', 'hostname' => 'club.example.test', 'separate_accounts' => true]);
        $this->shared = User::factory()->create(['email' => 'ana@example.test', 'password' => Hash::make('shared-password-1')]);
    }

    /** @return array<string, mixed> */
    private function registration(string $password): array
    {
        return ['name' => 'Ana', 'email' => 'ana@example.test', 'password' => $password, 'password_confirmation' => $password, ...BotTrap::fields(now()->subMinute())];
    }

    public function test_the_same_email_registers_separately_and_signs_in_only_where_it_belongs(): void
    {
        $this->post('/trade/register', $this->registration('trade-password-1'))->assertSessionHasNoErrors();
        $trade = User::query()->where('account_scope', $this->trade->id)->sole();
        $this->assertSame('ana@example.test', $trade->email);
        $this->assertNotSame($this->shared->id, $trade->id);
        $this->post('/trade/logout');

        // Taken among the shared accounts: the main store refuses a second one.
        $this->post('/register', $this->registration('another-password-1'))->assertSessionHasErrors('email');

        // Each password works only in its own channel.
        $this->post('/trade/login', ['email' => 'ana@example.test', 'password' => 'shared-password-1'])->assertSessionHasErrors('email');
        $this->post('/trade/login', ['email' => 'ana@example.test', 'password' => 'trade-password-1'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($trade, 'web');
    }

    public function test_a_shared_customer_is_signed_out_on_a_channel_with_its_own_accounts(): void
    {
        $this->actingAs($this->shared, 'web');

        $this->get('/trade/dashboard')->assertRedirect();
        $this->assertGuest('web');
    }

    public function test_password_reset_reaches_the_account_of_the_channel_asked_from(): void
    {
        Notification::fake();
        $trade = User::factory()->create(['email' => 'ana@example.test', 'account_scope' => $this->trade->id]);

        $this->post('/trade/forgot-password', ['email' => 'ana@example.test', ...BotTrap::fields(now()->subMinute())]);

        Notification::assertSentTo($trade, ResetPassword::class);
        Notification::assertNotSentTo($this->shared, ResetPassword::class);
    }

    public function test_store_api_tokens_work_only_in_their_own_accounts_channel(): void
    {
        $this->postJson('http://club.example.test/api/store/v1/auth/register', ['name' => 'Ana', 'email' => 'ana@example.test', 'password' => 'club-password-1'])->assertCreated();
        $club = User::query()->where('account_scope', $this->club->id)->sole();
        $token = $club->createToken('app', ['store'])->plainTextToken;

        $this->withToken($token)->getJson('http://club.example.test/api/store/v1/account')->assertOk()->assertJsonPath('data.id', $club->id);
        $this->withToken($token)->getJson('http://localhost/api/store/v1/account')->assertUnauthorized();

        // Signing in with the shared account's password does not work on the club's domain.
        $this->postJson('http://club.example.test/api/store/v1/auth/login', ['email' => 'ana@example.test', 'password' => 'shared-password-1'])->assertUnprocessable();
    }

    public function test_the_choice_is_made_when_the_channel_is_created(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(CreateChannel::class)
            ->fillForm(['name' => 'Outlet', 'code' => 'outlet', 'hostname' => 'outlet.example.test', 'separate_accounts' => true])
            ->call('create')
            ->assertHasNoFormErrors();
        $outlet = Channel::query()->where('code', 'outlet')->sole();
        $this->assertTrue($outlet->separate_accounts);

        Livewire::test(EditChannel::class, ['record' => $outlet->getRouteKey()])->assertFormFieldIsDisabled('separate_accounts');

        // Never switched later, and never on the main store.
        $outlet->update(['separate_accounts' => false]);
        $this->assertTrue($outlet->fresh()->separate_accounts);
        $main = Channel::query()->where('is_default', true)->sole();
        $main->forceFill(['separate_accounts' => true])->save();
        $this->assertFalse($main->fresh()->separate_accounts);
        $this->assertSame(CustomerAccounts::SHARED, app(CustomerAccounts::class)->scope($main));

        // A channel whose customers have their own accounts is not deleted.
        User::factory()->create(['account_scope' => $this->trade->id]);
        $this->assertFalse(auth('admin')->user()->can('delete', $this->trade));
        $this->assertTrue(auth('admin')->user()->can('delete', $outlet));
    }
}
