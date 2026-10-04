<?php

namespace PnShop\Credit;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PnShop\Acl\Models\AdminUser;
use PnShop\Credit\Contracts\BalanceAccount;
use PnShop\Credit\Exceptions\InsufficientBalance;
use PnShop\Credit\Models\BalanceTransaction;
use PnShop\Credit\Models\CreditAccount;
use PnShop\Credit\Models\GiftCard;
use PnShop\Customer\Models\User;
use PnShop\Payment\Models\Payment;
use PnShop\Sales\Models\Order;

/**
 * Every change to a gift card or store credit balance goes through here: one conditional
 * update (a balance never goes below zero) and one ledger row.
 */
final class Balances
{
    /** Code characters: no 0/O, 1/I/L. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * Issue a gift card. The code is returned once; only its hash is kept.
     *
     * @return array{0: GiftCard, 1: string} the card and its code, e.g. "7KQ2-…"
     */
    public function issueGiftCard(Money $amount, ?Carbon $expiresAt = null, ?string $recipientEmail = null, ?string $note = null, ?AdminUser $admin = null, ?User $owner = null, BalanceReason $reason = BalanceReason::Issued, ?Order $order = null): array
    {
        if (! $amount->isPositive()) {
            throw new \InvalidArgumentException('A gift card needs a positive amount.');
        }

        return DB::transaction(function () use ($amount, $expiresAt, $recipientEmail, $note, $admin, $owner, $reason, $order) {
            do {
                $code = $this->newCode();
            } while (GiftCard::query()->where('code_hash', GiftCard::hash($code))->exists());

            $card = new GiftCard(['expires_at' => $expiresAt, 'recipient_email' => $recipientEmail, 'note' => $note, 'user_id' => $owner?->id]);
            $card->forceFill([
                'code_hash' => GiftCard::hash($code),
                'last4' => substr(GiftCard::normalize($code), -4),
                'currency' => $amount->getCurrency()->getCurrencyCode(),
                'initial_amount' => 0,
                'balance' => 0,
                'is_active' => true,
            ])->save();

            $this->change($card, $amount, $reason, order: $order, admin: $admin, note: $note);
            $card->forceFill(['initial_amount' => $amount->getMinorAmount()->toInt()])->save();

            return [$card->refresh(), $code];
        });
    }

    /** The gift card with this code, if any (spendable or not). */
    public function findGiftCard(string $code): ?GiftCard
    {
        $normalized = GiftCard::normalize($code);

        return $normalized === '' ? null : GiftCard::query()->where('code_hash', GiftCard::hash($normalized))->first();
    }

    /** The customer's store credit in a currency (created empty). */
    public function creditAccount(User $customer, string $currency): CreditAccount
    {
        return CreditAccount::query()->firstOrCreate(['user_id' => $customer->id, 'currency' => $currency]);
    }

    /**
     * Add (positive) or take (negative) an amount, recorded in the ledger.
     *
     * @param  BalanceAccount&Model  $account
     *
     * @throws InsufficientBalance when taking more than the balance.
     */
    public function change(BalanceAccount $account, Money $amount, BalanceReason $reason, ?Order $order = null, ?Payment $payment = null, ?AdminUser $admin = null, ?string $note = null): BalanceTransaction
    {
        $currency = $amount->getCurrency()->getCurrencyCode();

        if ($currency !== $account->balanceMoney()->getCurrency()->getCurrencyCode()) {
            throw new \InvalidArgumentException("A {$account->balanceMoney()->getCurrency()} balance cannot change by {$currency}.");
        }

        $minor = $amount->getMinorAmount()->toInt();
        $column = $account->balanceColumn();

        return DB::transaction(function () use ($account, $minor, $column, $currency, $reason, $order, $payment, $admin, $note) {
            $update = $account->newQuery()->whereKey($account->getKey());

            if ($minor < 0) {
                $update->where($column, '>=', -$minor);
            }

            if ($update->increment($column, $minor) === 0) {
                throw new InsufficientBalance(__('The balance of :account is not enough.', ['account' => $account->label()]));
            }

            $after = (int) $account->newQuery()->whereKey($account->getKey())->value($column);
            $account->setAttribute($column, $after);

            return BalanceTransaction::query()->create([
                'account_type' => $account->getMorphClass(),
                'account_id' => $account->getKey(),
                'currency' => $currency,
                'amount' => $minor,
                'balance_after' => $after,
                'reason' => $reason,
                'order_id' => $order?->id,
                'payment_id' => $payment?->id,
                'admin_user_id' => $admin?->id,
                'note' => $note === null ? null : mb_substr($note, 0, 500),
            ]);
        });
    }

    private function newCode(): string
    {
        $characters = '';

        for ($i = 0; $i < 16; $i++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($characters, 4));
    }
}
