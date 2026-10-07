<?php

namespace PnShop\Promotion;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Collection;
use PnShop\Cart\CartItemDTO;

/**
 * The discounts one promotion gives, per cart line. A line never gets more off than is
 * left of it after earlier promotions and this promotion's other actions.
 */
final class Discounts
{
    /** @var array<string, Money> line key => amount off */
    private array $amounts = [];

    private bool $freeShipping = false;

    public function __construct(private PromotionContext $context) {}

    /**
     * Take an amount off one line, capped at what is left of it. Returns what was taken.
     */
    public function off(CartItemDTO $item, Money $amount): Money
    {
        $key = PromotionContext::lineKey($item);
        $left = $this->left($item);
        $amount = $amount->isGreaterThan($left) ? $left : $amount;

        if ($amount->isPositive()) {
            $this->amounts[$key] = ($this->amounts[$key] ?? Money::zero($this->context->currency()))->plus($amount);
        }

        return $amount;
    }

    /**
     * Spread a fixed amount over the lines in proportion to what is left of each; the
     * rounding remainder goes to the last line that can take it.
     *
     * @param  Collection<int, CartItemDTO>  $items
     */
    public function spread(Collection $items, Money $amount): void
    {
        $items = $items->filter(fn (CartItemDTO $item) => $this->left($item)->isPositive())->values();
        $base = $items->reduce(fn (Money $sum, CartItemDTO $item) => $sum->plus($this->left($item)), Money::zero($this->context->currency()));

        if ($items->isEmpty() || ! $base->isPositive()) {
            return;
        }

        $amount = $amount->isGreaterThan($base) ? $base : $amount;
        $given = Money::zero($this->context->currency());
        $baseMinor = $base->getMinorAmount()->toInt();

        foreach ($items as $index => $item) {
            $share = $index === $items->count() - 1
                ? $amount->minus($given)
                : Money::ofMinor(
                    // Big integers: amount × line can exceed 64 bits in zero-decimal currencies.
                    BigInteger::of($amount->getMinorAmount()->toInt())->multipliedBy($this->left($item)->getMinorAmount()->toInt())->quotient($baseMinor),
                    $this->context->currency(),
                );

            $given = $given->plus($this->off($item, $share));
        }
    }

    public function percentOff(CartItemDTO $item, float $percent): Money
    {
        $left = $this->left($item);

        $factor = BigDecimal::of((string) min(100, max(0, $percent)))->dividedBy(100, 8, RoundingMode::HalfUp);

        return $this->off($item, $left->multipliedBy($factor, RoundingMode::HalfUp));
    }

    public function freeShipping(): void
    {
        $this->freeShipping = true;
    }

    public function givesFreeShipping(): bool
    {
        return $this->freeShipping;
    }

    /** What is left of a line after earlier promotions and this one. Gift cards are never discounted. */
    public function left(CartItemDTO $item): Money
    {
        if ($item->giftCard) {
            return Money::zero($this->context->currency());
        }

        $mine = $this->amounts[PromotionContext::lineKey($item)] ?? null;

        return $mine === null ? $this->context->remaining($item) : $this->context->remaining($item)->minus($mine);
    }

    /**
     * @return array<string, Money>
     */
    public function amounts(): array
    {
        return $this->amounts;
    }

    public function total(): Money
    {
        return array_reduce($this->amounts, fn (Money $sum, Money $amount) => $sum->plus($amount), Money::zero($this->context->currency()));
    }
}
