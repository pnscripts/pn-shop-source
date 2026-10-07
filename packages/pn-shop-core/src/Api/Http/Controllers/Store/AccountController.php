<?php

namespace PnShop\Api\Http\Controllers\Store;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PnShop\Api\Http\Controllers\ApiController;
use PnShop\Api\Http\Resources\OrderPresenter;
use PnShop\Customer\Models\CustomerAddress;
use PnShop\Customer\Models\User;
use PnShop\Customer\PostalAddress;
use PnShop\Sales\Models\Order;

/**
 * The signed-in customer's account (requires a customer token).
 */
class AccountController extends ApiController
{
    /**
     * @return array<string, mixed>
     */
    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            // False only when the shop requires verified addresses and this one is not yet.
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }

    /**
     * Show the account
     *
     * @return array<string, mixed>
     */
    public function show(Request $request): array
    {
        return ['data' => self::present($this->user($request))];
    }

    /**
     * Resend the verification email
     *
     * When the shop requires verified email addresses, an unverified customer cannot place
     * orders. This sends the link again; it opens on the shop's website and works without
     * signing in there.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['sent' => false, 'email_verified' => true]]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['data' => ['sent' => true, 'email_verified' => false]], 202);
    }

    /**
     * Update the account
     *
     * Name and phone. Email and password are changed on the website.
     *
     * @return array<string, mixed>
     */
    public function update(Request $request): array
    {
        $user = $this->user($request);

        $user->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]));

        return ['data' => self::present($user)];
    }

    /**
     * List my orders
     *
     * Newest first.
     *
     * @return array<string, mixed>
     */
    public function orders(Request $request): array
    {
        $orders = $this->user($request)->orders()->with('items')->reorder()->orderByDesc('id')->cursorPaginate($this->perPage($request));

        return $this->paginated($orders, fn (Order $order) => OrderPresenter::summary($order));
    }

    /**
     * List my addresses
     *
     * @return array<string, mixed>
     */
    public function addresses(Request $request): array
    {
        return ['data' => $this->user($request)->addresses->map(fn (CustomerAddress $address) => OrderPresenter::address($address))->values()->all()];
    }

    /**
     * Add an address
     *
     * The first address becomes the default for shipping and billing.
     */
    public function storeAddress(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $address = $user->addresses()->create($request->validate(PostalAddress::rules()));

        if ($user->addresses()->count() === 1) {
            $address->makeDefault('both');
        }

        return response()->json(['data' => OrderPresenter::address($address->refresh())], 201);
    }

    /**
     * Change an address
     *
     * Send `default` (shipping, billing or both) to make it the default.
     *
     * @return array<string, mixed>
     */
    public function updateAddress(Request $request, int $address): array
    {
        $model = $this->address($request, $address);
        $model->update($request->validate(PostalAddress::rules()));

        if ($request->filled('default')) {
            $model->makeDefault($request->validate(['default' => ['in:shipping,billing,both']])['default']);
        }

        return ['data' => OrderPresenter::address($model->refresh())];
    }

    /**
     * Delete an address
     */
    public function destroyAddress(Request $request, int $address): JsonResponse
    {
        $this->address($request, $address)->delete();

        return response()->json(null, 204);
    }

    private function user(Request $request): User
    {
        return $this->customer($request) ?? abort(401);
    }

    /**
     * One of the customer's own addresses; others are "not found".
     */
    private function address(Request $request, int $id): CustomerAddress
    {
        return $this->user($request)->addresses()->whereKey($id)->firstOrFail();
    }
}
