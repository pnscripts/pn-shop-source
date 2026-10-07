<?php

namespace PnShop\Storefront\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use PnShop\Cart\GiftCardRecipientRules;

class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variant_id' => ['required_without:product_id', 'nullable', 'integer'],
            'product_id' => [
                'required_without:variant_id',
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            ...GiftCardRecipientRules::rules(),
        ];
    }
}
