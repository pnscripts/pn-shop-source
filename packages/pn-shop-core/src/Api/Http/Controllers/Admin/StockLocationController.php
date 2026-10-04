<?php

namespace PnShop\Api\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PnShop\Inventory\Models\StockLocation;

/**
 * Stock locations: warehouses and shops that hold stock. Needs catalog.inventory.manage.
 * Stock per location is changed with POST /variants/{id}/stock and /stock/transfers.
 */
class StockLocationController extends AdminController
{
    /**
     * List stock locations
     *
     * The default location first, then by position.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        Gate::authorize('viewAny', StockLocation::class);

        return ['data' => StockLocation::query()->ordered()->get()->map(fn (StockLocation $location) => $this->present($location))->all()];
    }

    /**
     * Show a stock location
     *
     * @return array<string, mixed>
     */
    public function show(StockLocation $stockLocation): array
    {
        Gate::authorize('view', $stockLocation);

        return ['data' => $this->present($stockLocation)];
    }

    /**
     * Create a stock location
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', StockLocation::class);

        $location = StockLocation::query()->create($request->validate($this->rules(null)));

        return response()->json(['data' => $this->present($location->refresh())], 201);
    }

    /**
     * Update a stock location
     *
     * Send `is_default: true` to make it the default location.
     *
     * @return array<string, mixed>
     */
    public function update(Request $request, StockLocation $stockLocation): array
    {
        Gate::authorize('update', $stockLocation);

        $data = $request->validate([...$this->rules($stockLocation), 'is_default' => ['sometimes', 'accepted']]);

        if ($stockLocation->is_default && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw ValidationException::withMessages(['is_active' => __('The default location cannot be turned off.')]);
        }

        $stockLocation->update($data);

        if ($data['is_default'] ?? false) {
            $stockLocation->makeDefault();
        }

        return ['data' => $this->present($stockLocation->refresh())];
    }

    /**
     * Delete a stock location
     *
     * Refused (403) for the default location and for locations that still hold or reserve stock.
     */
    public function destroy(StockLocation $stockLocation): JsonResponse
    {
        Gate::authorize('delete', $stockLocation);

        $stockLocation->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StockLocation $location): array
    {
        return [
            'id' => $location->id,
            'code' => $location->code,
            'name' => $location->name,
            'is_default' => $location->is_default,
            'is_active' => $location->is_active,
            'sells_online' => $location->sells_online,
            'position' => $location->position,
            'address' => $location->address,
            'city' => $location->city,
            'postcode' => $location->postcode,
            'country_code' => $location->country_code,
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(?StockLocation $location): array
    {
        $required = $location === null ? ['required'] : ['sometimes', 'required'];

        return [
            'code' => [...$required, 'string', 'alpha_dash', 'max:64', Rule::unique('stock_locations', 'code')->ignore($location?->id)],
            'name' => [...$required, 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sells_online' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postcode' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', Rule::exists('countries', 'code')],
        ];
    }
}
