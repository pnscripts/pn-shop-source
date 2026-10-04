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
        $user = User::modelClass()::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // The storefront the account was opened on (accounts are shared by all channels).
        $user->forceFill(['channel_id' => app(Channels::class)->current()->id])->save();

        event(new Registered($user));

        return $user;
    }
}
