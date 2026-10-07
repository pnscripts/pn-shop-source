<?php

namespace PnShop\Api\Http\Controllers\Store;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use PnShop\Api\Http\Controllers\ApiController;
use PnShop\Api\Http\Middleware\StoreCustomer;
use PnShop\Cart\CartRepository;
use PnShop\Customer\CustomerAccounts;
use PnShop\Customer\Models\User;
use PnShop\Customer\Registration;

/**
 * Customer accounts for headless storefronts and apps. Signing in returns a bearer token
 * (send it as "Authorization: Bearer <token>"); it is valid for TOKEN_DAYS days.
 */
class AuthController extends ApiController
{
    public const TOKEN_DAYS = 90;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private CartRepository $carts) {}

    /**
     * Create an account
     *
     * Registers a customer and returns a token, like signing in.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', app(CustomerAccounts::class)->uniqueEmail()],
            'password' => ['required', Password::defaults()],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = app(Registration::class)->register($data['name'], $data['email'], $data['password']);

        return response()->json($this->issue($request, $user, $data['device_name'] ?? null), 201);
    }

    /**
     * Sign in
     *
     * Exchanges email and password for a token. A guest cart sent as X-Cart-Token is merged
     * into the customer's cart.
     *
     * @return array<string, mixed>
     */
    public function login(Request $request): array
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $throttleKey = 'api-login:'.Str::transliterate(Str::lower($data['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            event(new Lockout($request));
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)])]);
        }

        $user = User::modelClass()::query()->where('email', $data['email'])->where('account_scope', app(CustomerAccounts::class)->scope())->first();

        // Without an account the password is hashed anyway (about as slow as checking it), so
        // the response time does not reveal which emails have accounts.
        $valid = $user !== null ? Hash::check($data['password'], $user->password) : Hash::make($data['password']) === '';

        if ($user === null || ! $valid) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);

        return $this->issue($request, $user, $data['device_name'] ?? null);
    }

    /**
     * Sign out
     *
     * Revokes the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $this->customer($request)?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(null, 204);
    }

    /**
     * @return array{data: array{token: string, token_type: string, expires_at: string, customer: array<string, mixed>}}
     */
    private function issue(Request $request, User $user, ?string $deviceName): array
    {
        $this->carts->mergeGuestCart($user, $this->guestCartToken($request));

        $expiresAt = now()->addDays(self::TOKEN_DAYS);
        $token = $user->createToken($deviceName ?: 'Store API', ['store'], $expiresAt);

        return ['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'customer' => AccountController::present($user),
        ]];
    }

    private function guestCartToken(Request $request): ?string
    {
        $token = $request->header(StoreCustomer::CART_HEADER);

        return is_string($token) && Str::isUuid($token) ? $token : null;
    }
}
