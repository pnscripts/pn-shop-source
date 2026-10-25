<?php

namespace PnShop\Plugins\BgEuro;

use Brick\Money\Money;
use Illuminate\Support\Carbon;
use PnShop\Extension\Plugin;
use PnShop\Money\MoneyDecorators;
use PnShop\Plugins\BgEuro\Console\EuroCheckCommand;
use Throwable;

class BgEuroPlugin extends Plugin
{
    protected function bootPlugin(): void
    {
        $this->app->make(MoneyDecorators::class)->add(function (string $text, Money $money, string $locale): string {
            if ($money->getCurrency()->getCurrencyCode() !== 'EUR' || ! $this->showsLeva()) {
                return $text;
            }

            return $text.' ('.Lev::fromEuro($money)->formatToLocale($locale).')';
        });

        if ($this->app->runningInConsole()) {
            $this->commands([EuroCheckCommand::class]);
        }
    }

    private function showsLeva(): bool
    {
        if (! (bool) $this->setting('show_leva')) {
            return false;
        }

        $until = trim((string) $this->setting('show_leva_until'));

        try {
            return $until === '' || Carbon::today()->lessThanOrEqualTo(Carbon::createFromFormat('Y-m-d', $until)->startOfDay());
        } catch (Throwable) {
            return true;
        }
    }
}
