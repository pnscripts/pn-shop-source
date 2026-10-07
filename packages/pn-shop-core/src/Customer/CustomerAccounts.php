<?php

namespace PnShop\Customer;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use PnShop\Channel\Channels;
use PnShop\Channel\Models\Channel;
use PnShop\Customer\Models\User;

/**
 * Which customer accounts the current storefront uses. By default accounts are shared by
 * every channel; a channel created with "separate customer accounts" has its own, so the
 * same email address can have an account there and a shared one elsewhere. Every lookup of
 * a customer by email (sign-in, registration, password reset, Store API) goes through here.
 */
final class CustomerAccounts
{
    /** account_scope of accounts shared by all channels. */
    public const SHARED = 0;

    public function __construct(private Channels $channels) {}

    public function scope(?Channel $channel = null): int
    {
        $channel ??= $this->channels->current();

        return $channel->separate_accounts ? (int) $channel->getKey() : self::SHARED;
    }

    /**
     * Credentials for Auth::attempt() and the password broker, limited to this storefront's accounts.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function credentials(array $credentials): array
    {
        return [...$credentials, 'account_scope' => $this->scope()];
    }

    /** "Email not taken" among this storefront's accounts (or the given scope's). */
    public function uniqueEmail(?int $scope = null): Unique
    {
        return Rule::unique(User::modelClass(), 'email')->where('account_scope', $scope ?? $this->scope());
    }

    public function belongsHere(User $user): bool
    {
        return (int) $user->getAttribute('account_scope') === $this->scope();
    }
}
