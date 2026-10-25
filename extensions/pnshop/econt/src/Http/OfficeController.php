<?php

namespace PnShop\Plugins\Econt\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PnShop\Plugins\Econt\EcontLocations;
use PnShop\Plugins\Econt\SelectedOffice;
use Throwable;

/**
 * Offices for the checkout's office picker, and the shopper's choice.
 */
class OfficeController extends Controller
{
    public function index(Request $request, EcontLocations $locations, SelectedOffice $selected): JsonResponse
    {
        $request->validate(['postcode' => ['nullable', 'string', 'max:12'], 'city' => ['nullable', 'string', 'max:100'], 'search' => ['nullable', 'string', 'max:100']]);

        try {
            $city = $locations->cityByPostcode((string) $request->query('postcode', ''), (string) $request->query('city', ''));
            $offices = $city !== null ? $locations->offices($city['id']) : [];
            $search = mb_strtolower(trim((string) $request->query('search', '')));

            if ($city === null && mb_strlen($search) >= 2) {
                $offices = $locations->offices();
            }

            if ($search !== '') {
                $offices = array_values(array_filter($offices, fn (array $office) => str_contains(mb_strtolower($office['name'].' '.$office['address']), $search)));
            }
        } catch (Throwable) {
            return response()->json(['offices' => [], 'selected' => null, 'error' => __('Econt offices cannot be loaded right now. Please try again in a moment.')], 503);
        }

        return response()->json([
            'offices' => array_slice(array_map(fn (array $office) => ['code' => $office['code'], 'name' => $office['name'], 'address' => $office['address']], $offices), 0, 100),
            'selected' => $selected->code(),
        ]);
    }

    public function store(Request $request, SelectedOffice $selected): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        try {
            $chosen = $selected->choose($data['code']);
        } catch (Throwable) {
            $chosen = false;
        }

        return $chosen
            ? response()->json(['selected' => $data['code']])
            : response()->json(['message' => __('This Econt office is not available. Please choose another.')], 422);
    }
}
