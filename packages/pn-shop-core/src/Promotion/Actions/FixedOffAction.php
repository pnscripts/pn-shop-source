<?php

namespace PnShop\Promotion\Actions;

use Filament\Forms\Components\TextInput;
use PnShop\Cart\CartItemDTO;
use PnShop\Localization\CurrencyConverter;
use PnShop\Promotion\Contracts\ActionType;
use PnShop\Promotion\Discounts;
use PnShop\Promotion\Fields;
use PnShop\Promotion\PromotionContext;

/**
 * A fixed amount off the order (or off the matching lines), spread over the lines so
 * tax and refunds stay exact; never more than those lines cost.
 */
class FixedOffAction implements ActionType
{
    public function key(): string
    {
        return 'fixed_off';
    }

    public function label(): string
    {
        return 'Fixed amount off';
    }

    public function fields(): array
    {
        return [
            TextInput::make('amount')->label('Amount off')->numeric()->minValue(0.01)->required(),
            Fields::products('Only these products'),
            Fields::categories('Only these categories'),
        ];
    }

    public function rules(): array
    {
        return ['amount' => ['required', 'numeric', 'min:0.01'], ...Fields::scopeRules()];
    }

    public function apply(PromotionContext $context, array $data, Discounts $discounts): void
    {
        $discounts->spread(
            $context->items()->filter(fn (CartItemDTO $item) => $context->inScope($item, $data)),
            // Entered in the default currency.
            app(CurrencyConverter::class)->fromDefault((string) ($data['amount'] ?? 0), $context->currency()),
        );
    }
}
