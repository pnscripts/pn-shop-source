<?php

namespace PnShop\Customer;

use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use PnShop\Channel\Channels;
use PnShop\Customer\Models\User;

/**
 * Creates customer accounts (storefront and Store API) through the shop's customer model,
 * and announces them (verification email, welcome mails from plugins).
 */
class Registration
{
    public function register(string $name, string $email, string $password): User
    {
        $user = User::modelClass()::make([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // The storefront the account was opened on, and whose accounts it belongs to (shared
        // by all channels unless that channel keeps separate customer accounts); set on the
        // insert, where the email is unique per account scope.
        $user->forceFill(['channel_id' => app(Channels::class)->current()->id, 'account_scope' => app(CustomerAccounts::class)->scope()])->save();

        event(new Registered($user));

        return $user;
    }
}
