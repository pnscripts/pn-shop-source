<?php

namespace PnShop\Cart;

/**
 * Validation for who receives gift cards added to the cart (storefront and Store API):
 * optional; without an email the cards go to the buyer.
 */
final class GiftCardRecipientRules
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'gift_card' => ['nullable', 'array:email,name,message'],
            'gift_card.email' => ['nullable', 'string', 'email', 'max:255'],
            'gift_card.name' => ['nullable', 'string', 'max:100'],
            'gift_card.message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
