import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/hooks/use-translations';
import { type CartSummary } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

/**
 * Gift cards entered in the cart, a field for another code, and the customer's store credit.
 */
export function GiftCardForm({ cart }: { cart: CartSummary }) {
    const t = useTranslations();
    const form = useForm({ gift_card: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('cart.gift-cards.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <div className="mb-4 grid gap-2 text-sm" data-testid="gift-cards">
            {(cart.gift_cards ?? []).map((card) => (
                <div key={card.id} className="flex items-center justify-between gap-2 rounded-lg border border-dashed px-3 py-2">
                    <span>
                        {card.label} <span className="text-muted-foreground">({card.balance.formatted})</span>
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.delete(route('cart.gift-cards.destroy', { giftCard: card.id }), { preserveScroll: true })}
                    >
                        {t('Remove')}
                    </Button>
                </div>
            ))}
            <form onSubmit={submit}>
                <div className="flex gap-2">
                    <Input
                        value={form.data.gift_card}
                        onChange={(event) => form.setData('gift_card', event.target.value)}
                        placeholder={t('Gift card code')}
                        aria-label={t('Gift card code')}
                        autoComplete="off"
                        maxLength={64}
                    />
                    <Button type="submit" variant="outline" disabled={form.processing || form.data.gift_card.trim() === ''}>
                        {t('Apply')}
                    </Button>
                </div>
                <InputError message={form.errors.gift_card} className="mt-1" />
            </form>
            {cart.store_credit && (
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={cart.store_credit.used}
                        onChange={(event) => router.put(route('cart.store-credit.update'), { use: event.target.checked }, { preserveScroll: true })}
                    />
                    {t('Use my store credit (:amount available)', { amount: cart.store_credit.available.formatted })}
                </label>
            )}
        </div>
    );
}

/**
 * What gift cards and store credit pay, and what is left to pay.
 */
export function BalanceLines({
    lines,
    amountDue,
}: {
    lines: { label: string; amount: { formatted: string } }[];
    amountDue: { formatted: string } | null;
}) {
    const t = useTranslations();

    if (lines.length === 0 || amountDue === null) {
        return null;
    }

    return (
        <div className="mt-2 grid gap-1 border-t pt-2 text-sm" data-testid="balance-lines">
            {lines.map((line, index) => (
                <div key={index} className="flex justify-between">
                    <span className="text-muted-foreground">{line.label}</span>
                    <span>−{line.amount.formatted}</span>
                </div>
            ))}
            <div className="flex justify-between font-semibold">
                <span>{t('To pay')}</span>
                <span>{amountDue.formatted}</span>
            </div>
        </div>
    );
}
