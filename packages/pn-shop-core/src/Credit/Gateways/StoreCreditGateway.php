<?php

namespace PnShop\Credit\Gateways;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PnShop\Credit\BalanceReason;
use PnShop\Credit\Balances;
use PnShop\Credit\Contracts\BalanceAccount;
use PnShop\Payment\Contracts\PaymentGateway;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentContext;
use PnShop\Payment\PaymentResult;

/**
 * Payments made with a gift card or store credit (see CartBalances). Never offered as a
 * method at checkout; refunds go back to the balance the payment came from.
 *
 * A payment's reference names its balance: "gift_card:12" or "credit_account:3".
 */
final class StoreCreditGateway implements PaymentGateway
{
    public const CODE = 'store_credit';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Gift card or store credit';
    }

    public function settings(): array
    {
        return [];
    }

    public function isAvailable(PaymentContext $context, PaymentMethod $method): bool
    {
        return false;
    }

    public function initiate(Payment $payment, PaymentMethod $method): PaymentResult
    {
        return PaymentResult::paid($payment->reference);
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(Payment $payment, Money $amount, PaymentMethod $method): PaymentResult
    {
        $account = self::account($payment);

        if ($account === null) {
            return PaymentResult::failed(__('The gift card or store credit of this payment no longer exists.'));
        }

        app(Balances::class)->change($account, $amount, BalanceReason::Refund, $payment->order, $payment);

        return PaymentResult::refunded($payment->reference);
    }

    public function instructions(Payment $payment, PaymentMethod $method): ?string
    {
        return null;
    }

    /**
     * @return (BalanceAccount&Model)|null
     */
    public static function account(Payment $payment): ?BalanceAccount
    {
        [$type, $id] = array_pad(explode(':', (string) $payment->reference, 2), 2, null);
        $class = $type === null ? null : Relation::getMorphedModel($type);

        if ($class === null || ! is_subclass_of($class, BalanceAccount::class)) {
            return null;
        }

        $account = $class::query()->find((int) $id);

        return $account instanceof BalanceAccount ? $account : null;
    }
}
