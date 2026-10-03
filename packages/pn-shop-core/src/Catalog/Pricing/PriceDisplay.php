<?php

namespace PnShop\Catalog\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use PnShop\Money\MoneyPresenter;
use PnShop\Settings\Settings;
use PnShop\Tax\Contracts\TaxProvider;
use PnShop\Tax\TaxableLine;
use PnShop\Tax\TaxRequest;
use Throwable;

/**
 * How catalog prices are shown to the current customer:
 *
 * - hidden from guests when the shop shows prices to signed-in customers only
 *   (setting customers.show_prices_to_guests);
 * - with or without tax as their customer group asks (B2B customers usually see net
 *   prices), converted with the store country's rates when that differs from how prices
 *   are entered (setting tax.prices_include_tax).
 *
 * Only what is shown changes: the cart and checkout charge the same amounts and show the
 * tax as its own line. One per request (scoped).
 */
class PriceDisplay
{
    /** @var array<string, BigDecimal> tax class => share of the net price the tax takes */
    private array $rates = [];

    public function __construct(private PriceResolver $prices, private Settings $settings, private TaxProvider $tax) {}

    /** Whether the current customer may see prices (and buy). */
    public function visible(): bool
    {
        return $this->prices->context()->customer !== null || (bool) ($this->settings->get('customers.show_prices_to_guests') ?? true);
    }

    /** Whether the prices shown include tax. */
    public function includesTax(): bool
    {
        return $this->prices->customerGroup()->prices_include_tax ?? $this->enteredWithTax();
    }

    /**
     * A catalog amount as shown to this customer, or null when prices are hidden.
     *
     * @return array<string, mixed>|null
     */
    public function present(?Money $amount, ?int $taxClassId = null): ?array
    {
        if ($amount === null || ! $this->visible()) {
            return null;
        }

        return MoneyPresenter::present($this->amount($amount, $taxClassId));
    }

    /** The amount with or without tax, as this customer sees prices. */
    public function amount(Money $amount, ?int $taxClassId = null): Money
    {
        $entered = $this->enteredWithTax();

        if ($this->includesTax() === $entered) {
            return $amount;
        }

        $multiplier = BigDecimal::one()->plus($this->rate($taxClassId, $amount->getCurrency()->getCurrencyCode()));

        if ($multiplier->isEqualTo(1)) {
            return $amount;
        }

        $converted = $entered ? $amount->getAmount()->dividedBy($multiplier, 10, RoundingMode::HalfUp) : $amount->getAmount()->multipliedBy($multiplier);

        return Money::of($converted, $amount->getCurrency(), roundingMode: RoundingMode::HalfUp);
    }

    /**
     * For pages and API responses: {visible, includes_tax}.
     *
     * @return array{visible: bool, includes_tax: bool}
     */
    public function toArray(): array
    {
        return ['visible' => $this->visible(), 'includes_tax' => $this->includesTax()];
    }

    private function enteredWithTax(): bool
    {
        return (bool) ($this->settings->get('tax.prices_include_tax') ?? true);
    }

    /**
     * The store country's tax for the class, as a share of the net price; one calculation
     * per class and request.
     */
    private function rate(?int $taxClassId, string $currency): BigDecimal
    {
        $key = $taxClassId.'|'.$currency;

        if (isset($this->rates[$key])) {
            return $this->rates[$key];
        }

        $country = strtoupper(trim((string) $this->settings->get('tax.store_country')));

        if ($country === '') {
            return $this->rates[$key] = BigDecimal::zero();
        }

        try {
            $base = Money::of(1_000_000, $currency);
            $tax = $this->tax->calculate(new TaxRequest([new TaxableLine('display', $base, $taxClassId)], $currency, $country, null, false, $this->prices->context()->customer))->total();
            $rate = $tax->getAmount()->dividedBy($base->getAmount(), 10, RoundingMode::HalfUp);
        } catch (Throwable) {
            $rate = BigDecimal::zero();
        }

        return $this->rates[$key] = $rate;
    }
}
