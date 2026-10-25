<?php

namespace PnShop\Plugins\Speedy;

use Illuminate\Support\Facades\Session;

/**
 * The Speedy office the shopper picked at checkout (in their session), or sent with the
 * Store API checkout as "speedy_office_id".
 */
final class SelectedOffice
{
    private const KEY = 'pnshop_speedy.office';

    public function __construct(private SpeedyLocations $locations) {}

    public function id(): ?int
    {
        $input = request()->input('speedy_office_id');

        if (is_numeric($input) && (int) $input > 0) {
            return (int) $input;
        }

        $id = Session::isStarted() ? Session::get(self::KEY) : null;

        return is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * @return array{id: int, name: string, address: string, site_id: int|null}|null
     */
    public function office(): ?array
    {
        $id = $this->id();

        return $id === null ? null : $this->locations->office($id);
    }

    public function choose(int $id): bool
    {
        if ($this->locations->office($id) === null) {
            return false;
        }

        Session::put(self::KEY, $id);

        return true;
    }
}
