<?php

namespace PnShop\Promotion\Conditions;

use Filament\Forms\Components\TextInput;
use PnShop\Localization\CurrencyConverter;
use PnShop\Promotion\Contracts\ConditionType;
use PnShop\Promotion\PromotionContext;

class SubtotalCondition implements ConditionType
{
    public function key(): string
    {
        return 'subtotal';
    }

    public function label(): string
    {
        return 'Cart subtotal is at least';
    }

    public function fields(): array
    {
        return [TextInput::make('min')->label('Minimum subtotal')->numeric()->minValue(0)->required()];
    }

    public function rules(): array
    {
        return ['min' => ['required', 'numeric', 'min:0']];
    }

    public function passes(PromotionContext $context, array $data): bool
    {
        // Entered in the default currency.
        $min = app(CurrencyConverter::class)->fromDefault((string) ($data['min'] ?? 0), $context->currency());

        return $context->totals->subtotal->isGreaterThanOrEqualTo($min);
    }
}
