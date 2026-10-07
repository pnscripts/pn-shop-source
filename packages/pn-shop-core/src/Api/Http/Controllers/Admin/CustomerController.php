<?php

namespace PnShop\Api\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use PnShop\Api\Http\Resources\OrderPresenter;
use PnShop\Customer\Models\CustomerAddress;
use PnShop\Customer\Models\User;

class CustomerController extends AdminController
{
    /**
     * List customers
     *
     * Filters: `filter[email]` (exact), `filter[group_id]`, `filter[updated_since]`.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        Gate::authorize('viewAny', User::class);

        $filter = (array) $request->input('filter', []);

        $customers = $this->updatedSince(User::modelClass()::query(), $request)
            ->when(isset($filter['email']), fn (Builder $query) => $query->where('email', (string) $filter['email']))
            ->when(isset($filter['group_id']), fn (Builder $query) => $query->where('customer_group_id', (int) $filter['group_id']))
            ->withCount('orders')
            ->orderBy('id')
            ->cursorPaginate($this->perPage($request))
            ->withQueryString();

        return $this->paginated($customers, fn (User $customer) => $this->present($customer));
    }

    /**
     * Show a customer
     *
     * With the address book.
     *
     * @return array<string, mixed>
     */
    public function show(User $customer): array
    {
        Gate::authorize('view', $customer);

        $customer->loadCount('orders')->load('addresses');

        return ['data' => [
            ...$this->present($customer),
            'addresses' => $customer->addresses->map(fn (CustomerAddress $address) => OrderPresenter::address($address))->values()->all(),
        ]];
    }

    /**
     * Update a customer
     *
     * Name, phone and customer group (which can have its own prices).
     *
     * @return array<string, mixed>
     */
    public function update(Request $request, User $customer): array
    {
        Gate::authorize('update', $customer);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'customer_group_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customer_groups', 'id')],
        ]);

        $customer->forceFill($data)->save();

        return ['data' => $this->present($customer->loadCount('orders'))];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'customer_group_id' => $customer->customer_group_id,
            // The channel whose separate customer accounts this is; null when shared by all channels.
            'accounts_channel_id' => $customer->account_scope === 0 ? null : $customer->account_scope,
            'orders_count' => (int) $customer->getAttribute('orders_count'),
            'email_verified_at' => $customer->email_verified_at?->toIso8601String(),
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }
}
