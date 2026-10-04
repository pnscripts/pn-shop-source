import { type Money, type Totals } from '@/types';
import { useEffect, useState } from 'react';

export type ShippingOption = {
    id: number;
    name: string;
    description: string | null;
    price: Money;
    /** Pickup at a stock location: where, and whether everything in the cart is in stock there. */
    pickup?: { location: string; address: string; in_stock: boolean } | null;
};

/** A gift card or store credit paying part of the order. */
export type BalancePayment = { label: string; amount: Money };

type Quote = { options: ShippingOption[]; selected: number | null; totals: Totals; balances?: BalancePayment[]; amount_due?: Money | null };

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Delivery options and totals for the address being entered. Asks the server (POST, so the
 * postcode stays out of URLs) shortly after the country, postcode or chosen method changes.
 */
export function useShippingQuote(countryCode: string, postcode: string, shippingMethodId: string, initialTotals: Totals) {
    const [quote, setQuote] = useState<Quote | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (countryCode.length !== 2) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);

            fetch(route('checkout.quote'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ country_code: countryCode, postcode, shipping_method_id: shippingMethodId || null }),
                signal: controller.signal,
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? (response.json() as Promise<Quote>) : null))
                .then((result) => result && setQuote(result))
                .catch(() => undefined)
                .finally(() => setLoading(false));
        }, 300);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [countryCode, postcode, shippingMethodId]);

    return {
        options: quote?.options ?? null,
        selected: quote?.selected ?? null,
        totals: quote?.totals ?? initialTotals,
        balances: quote?.balances ?? null,
        amountDue: quote?.amount_due ?? null,
        loading,
    };
}
