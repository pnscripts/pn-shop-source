<?php

namespace PnShop\Plugins\Speedy\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PnShop\Plugins\Speedy\SelectedOffice;
use PnShop\Plugins\Speedy\SpeedyLocations;
use Throwable;

/**
 * Offices for the checkout's office picker, and the shopper's choice.
 */
class OfficeController extends Controller
{
    public function index(Request $request, SpeedyLocations $locations, SelectedOffice $selected): JsonResponse
    {
        $request->validate(['postcode' => ['nullable', 'string', 'max:12'], 'search' => ['nullable', 'string', 'max:100']]);

        try {
            $site = $locations->siteByPostcode((string) $request->query('postcode', ''));
            $offices = $site !== null ? $locations->offices($site['id']) : [];
            $search = mb_strtolower(trim((string) $request->query('search', '')));

            if ($site === null && mb_strlen($search) >= 2) {
                $offices = $locations->offices();
            }

            if ($search !== '') {
                $offices = array_values(array_filter($offices, fn (array $office) => str_contains(mb_strtolower($office['name'].' '.$office['address']), $search)));
            }
        } catch (Throwable) {
            return response()->json(['offices' => [], 'selected' => null, 'error' => __('Speedy offices cannot be loaded right now. Please try again in a moment.')], 503);
        }

        return response()->json([
            'offices' => array_slice(array_map(fn (array $office) => ['code' => (string) $office['id'], 'name' => $office['name'], 'address' => $office['address']], $offices), 0, 100),
            'selected' => $selected->id() !== null ? (string) $selected->id() : null,
        ]);
    }

    public function store(Request $request, SelectedOffice $selected): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'integer', 'min:1']]);

        try {
            $chosen = $selected->choose((int) $data['code']);
        } catch (Throwable) {
            $chosen = false;
        }

        return $chosen
            ? response()->json(['selected' => (string) $data['code']])
            : response()->json(['message' => __('This Speedy office is not available. Please choose another.')], 422);
    }
}
