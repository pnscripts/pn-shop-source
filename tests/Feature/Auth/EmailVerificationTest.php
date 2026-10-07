<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PnShop\Security\BotTrap;
use PnShop\Settings\Settings;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(Settings::class)->set('customers', ['require_email_verification' => true]);
    }

    public function test_unverified_customers_are_asked_to_verify_only_when_the_setting_is_on(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));

        app(Settings::class)->set('customers', ['require_email_verification' => false]);
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->sendEmailVerificationNotification();
        Notification::assertNothingSent();
    }

    public function test_registering_sends_the_verification_email_when_required(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Ana',
            'email' => 'ana@example.test',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
            ...BotTrap::fields(now()->subMinute()),
        ]);

        Notification::assertSentTo(User::query()->where('email', 'ana@example.test')->sole(), VerifyEmail::class);
    }

    public function test_email_verification_screen_can_be_rendered()
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_email_can_be_verified()
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash()
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    private function link(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => $hash ?? sha1($user->email)]);
    }

    public function test_the_link_works_without_signing_in(): void
    {
        $user = User::factory()->unverified()->create();
        Event::fake([Verified::class]);

        $this->get($this->link($user))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your email address is verified. You can sign in now.');

        Event::assertDispatched(Verified::class);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest('web');
    }

    public function test_the_link_is_refused_unsigned_with_a_wrong_hash_or_for_another_account(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))->assertForbidden();
        $this->get($this->link($user, sha1('someone@example.test')))->assertForbidden();
        $this->actingAs(User::factory()->unverified()->create())->get($this->link($user))->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_store_api_customers_see_their_status_and_can_resend_the_link(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('app', ['store'])->plainTextToken;

        $this->withToken($token)->getJson('/api/store/v1/account')->assertOk()->assertJsonPath('data.email_verified', false);
        $this->withToken($token)->postJson('/api/store/v1/account/email/verification-notification')
            ->assertStatus(202)
            ->assertJsonPath('data.sent', true);
        Notification::assertSentTo($user, VerifyEmail::class);

        $user->markEmailAsVerified();
        $this->withToken($token)->postJson('/api/store/v1/account/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('data.sent', false);
        $this->withToken($token)->getJson('/api/store/v1/account')->assertJsonPath('data.email_verified', true);
    }
}
