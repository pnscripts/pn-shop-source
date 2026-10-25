<?php

namespace PnShop\Plugins\Econt;

use Illuminate\Support\Facades\Session;

/**
 * The Econt office the shopper picked at checkout (in their session), or sent with the
 * Store API checkout as "econt_office_code".
 */
final class SelectedOffice
{
    private const KEY = 'pnshop_econt.office';

    public function __construct(private EcontLocations $locations) {}

    public function code(): ?string
    {
        $input = request()->input('econt_office_code');

        if (is_string($input) && $input !== '') {
            return $input;
        }

        $code = Session::isStarted() ? Session::get(self::KEY) : null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * @return array{code: string, name: string, address: string, city_id: int|null}|null
     */
    public function office(): ?array
    {
        $code = $this->code();

        return $code === null ? null : $this->locations->office($code);
    }

    /**
     * Remember the office, if Econt knows it.
     */
    public function choose(string $code): bool
    {
        if ($this->locations->office($code) === null) {
            return false;
        }

        Session::put(self::KEY, $code);

        return true;
    }

    public function forget(): void
    {
        Session::forget(self::KEY);
    }
}
