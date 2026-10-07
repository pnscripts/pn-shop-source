<?php

namespace PnShop\Storefront\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use PnShop\Customer\CustomerAccounts;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                app(CustomerAccounts::class)->uniqueEmail((int) $this->user()?->getAttribute('account_scope'))->ignore($this->user()?->getKey()),
            ],
        ];
    }
}
