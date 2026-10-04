<?php

namespace PnShop\Sales\Checkout;

use Illuminate\Validation\Rule;
use PnShop\Customer\PostalAddress;

/**
 * Validation of checkout input, shared by the storefront form and the Store API.
 */
final class CheckoutRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $billing = array_map(
            fn (array $rules) => ['exclude_if:billing_same_as_shipping,true', ...$rules],
            PostalAddress::rules('billing.'),
        );

        return [
            'email' => ['required', 'email', 'max:255'],
            ...PostalAddress::rules('shipping.'),
            // Couriers need a phone number for the delivery.
            'shipping.phone' => ['required', 'string', 'max:50'],
            'billing_same_as_shipping' => ['boolean'],
            ...$billing,
            'save_address' => ['boolean'],
            'shipping_method_id' => ['nullable', 'integer'],
            // Not needed when gift cards or store credit pay everything (checked by CheckoutService).
            'payment_method_id' => [
                'nullable',
                'integer',
                Rule::exists('payment_methods', 'id')->where('is_active', true),
            ],
        ];
    }

    /**
     * Readable field names for validation messages.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $names = [
            'first_name' => __('first name'),
            'last_name' => __('last name'),
            'company' => __('company'),
            'line1' => __('street address'),
            'line2' => __('apartment, floor, etc.'),
            'city' => __('city'),
            'postcode' => __('postcode'),
            'region' => __('region'),
            'country_code' => __('country'),
            'phone' => __('phone'),
        ];

        $attributes = [];

        foreach (['shipping', 'billing'] as $type) {
            foreach ($names as $field => $name) {
                $attributes["{$type}.{$field}"] = $name;
            }
        }

        return $attributes;
    }
}
