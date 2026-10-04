import { AddressFields, emptyAddress, type AddressData, type Country } from '@/components/address-fields';
import { BotTrapFields, type BotTrapData } from '@/components/bot-trap';
import InputError from '@/components/input-error';
import { Slot } from '@/components/slot';
import { TotalsBreakdown } from '@/components/totals-breakdown';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useShippingQuote } from '@/hooks/use-shipping-quote';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { type CartSummary, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

type PaymentMethod = {
    id: number;
    name: string;
    description: string | null;
};

type SavedAddress = AddressData & { id: number; lines: string[]; is_default_shipping: boolean; is_default_billing: boolean };

const toAddressData = (address: SavedAddress): AddressData =>
    Object.fromEntries(Object.keys(emptyAddress()).map((key) => [key, address[key as keyof AddressData] ?? ''])) as AddressData;

export default function Checkout({
    cart,
    paymentMethods,
    countries,
    savedAddresses,
    defaults,
    botTrap,
    shippingRequired,
}: {
    cart: CartSummary;
    paymentMethods: PaymentMethod[];
    countries: Country[];
    savedAddresses: SavedAddress[];
    defaults: { email: string; first_name: string; last_name: string; phone: string };
    botTrap: BotTrapData;
    shippingRequired: boolean;
}) {
    const t = useTranslations();
    const { auth } = usePage<SharedData>().props;
    const defaultCountry = countries.length === 1 ? countries[0].code : '';
    const defaultShipping = savedAddresses.find((address) => address.is_default_shipping) ?? savedAddresses[0];
    const defaultBilling = savedAddresses.find((address) => address.is_default_billing);
    const [shippingChoice, setShippingChoice] = useState<number | 'new'>(defaultShipping?.id ?? 'new');

    const { data, setData, post, processing, errors } = useForm({
        email: defaults.email,
        shipping: defaultShipping
            ? toAddressData(defaultShipping)
            : { ...emptyAddress(defaultCountry), first_name: defaults.first_name, last_name: defaults.last_name, phone: defaults.phone },
        billing_same_as_shipping: !defaultBilling || defaultBilling.id === defaultShipping?.id,
        billing: defaultBilling ? toAddressData(defaultBilling) : emptyAddress(defaultCountry),
        save_address: savedAddresses.length === 0,
        payment_method_id: paymentMethods[0]?.id ? String(paymentMethods[0].id) : '',
        shipping_method_id: '',
        ...botTrap,
    });
    const fieldErrors = errors as Record<string, string | undefined>;
    const delivery = useShippingQuote(data.shipping.country_code, data.shipping.postcode, data.shipping_method_id, cart.totals);

    // Keep the customer's choice; fall back to the server's pick only when the chosen
    // option is not offered for the address (or nothing is chosen yet).
    useEffect(() => {
        if (delivery.options === null || delivery.options.some((option) => String(option.id) === data.shipping_method_id)) {
            return;
        }

        setData('shipping_method_id', delivery.selected === null ? '' : String(delivery.selected));
    }, [delivery.options, delivery.selected, data.shipping_method_id, setData]);

    const chooseShipping = (choice: number | 'new') => {
        setShippingChoice(choice);
        const saved = savedAddresses.find((address) => address.id === choice);
        setData('shipping', saved ? toAddressData(saved) : emptyAddress(defaultCountry));
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('checkout.store'));
    };

    return (
        <StorefrontLayout>
            <Head title={t('Checkout')} />
            <h1 className="mb-6 text-3xl font-semibold tracking-tight">{t('Checkout')}</h1>

            <form onSubmit={submit} className="relative grid gap-8 lg:grid-cols-3">
                <div className="grid content-start gap-8 lg:col-span-2">
                    <BotTrapFields value={data.contact_website} onChange={(value) => setData('contact_website', value)} error={fieldErrors.form} />
                    <section className="grid gap-2">
                        <Label htmlFor="email">{t('Email')}</Label>
                        <Input
                            id="email"
                            type="email"
                            autoComplete="email"
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value)}
                            required
                        />
                        <InputError message={errors.email} />
                    </section>

                    <section className="grid gap-4">
                        <h2 className="text-lg font-semibold">{t('Shipping address')}</h2>
                        {savedAddresses.length > 0 && (
                            <div className="grid gap-2 sm:grid-cols-2">
                                {savedAddresses.map((address) => (
                                    <label
                                        key={address.id}
                                        className={`cursor-pointer rounded-lg border p-3 text-sm ${shippingChoice === address.id ? 'border-primary ring-primary ring-1' : ''}`}
                                    >
                                        <input
                                            type="radio"
                                            name="shipping_choice"
                                            className="sr-only"
                                            checked={shippingChoice === address.id}
                                            onChange={() => chooseShipping(address.id)}
                                        />
                                        {address.lines.map((line) => (
                                            <span key={line} className="block">
                                                {line}
                                            </span>
                                        ))}
                                    </label>
                                ))}
                                <label
                                    className={`flex cursor-pointer items-center justify-center rounded-lg border border-dashed p-3 text-sm ${shippingChoice === 'new' ? 'border-primary ring-primary ring-1' : ''}`}
                                >
                                    <input
                                        type="radio"
                                        name="shipping_choice"
                                        className="sr-only"
                                        checked={shippingChoice === 'new'}
                                        onChange={() => chooseShipping('new')}
                                    />
                                    {t('Use a new address')}
                                </label>
                            </div>
                        )}
                        {(shippingChoice === 'new' || Object.keys(errors).some((key) => key.startsWith('shipping.'))) && (
                            <AddressFields
                                value={data.shipping}
                                onChange={(next) => setData('shipping', next)}
                                countries={countries}
                                errors={fieldErrors}
                                prefix="shipping."
                                idPrefix="shipping"
                                phoneRequired
                            />
                        )}
                        {savedAddresses.length > 0 && shippingChoice !== 'new' && !data.shipping.phone && (
                            <div className="grid gap-2">
                                <Label htmlFor="shipping-phone-only">{t('Phone')}</Label>
                                <Input
                                    id="shipping-phone-only"
                                    autoComplete="tel"
                                    value={data.shipping.phone}
                                    onChange={(event) => setData('shipping', { ...data.shipping, phone: event.target.value })}
                                    required
                                />
                                <InputError message={errors['shipping.phone' as keyof typeof errors]} />
                            </div>
                        )}
                        {auth.user && shippingChoice === 'new' && (
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.save_address} onCheckedChange={(checked) => setData('save_address', checked === true)} />
                                {t('Save this address to my account')}
                            </label>
                        )}
                    </section>

                    {shippingRequired && (
                        <section className="grid gap-3">
                            <h2 className="text-lg font-semibold">{t('Delivery')}</h2>
                            {delivery.options === null ? (
                                <p className="text-muted-foreground text-sm">{t('Enter your address to see the delivery options.')}</p>
                            ) : delivery.options.length === 0 ? (
                                <p className="text-destructive text-sm">{t('We cannot deliver to this address yet.')}</p>
                            ) : (
                                <div className={`grid gap-2 ${delivery.loading ? 'opacity-60' : ''}`}>
                                    {delivery.options.map((option) => (
                                        <label
                                            key={option.id}
                                            className={`flex cursor-pointer items-start justify-between gap-4 rounded-lg border p-3 text-sm ${String(option.id) === data.shipping_method_id ? 'border-primary ring-primary ring-1' : ''}`}
                                        >
                                            <span className="flex items-start gap-3">
                                                <input
                                                    type="radio"
                                                    name="shipping_method_id"
                                                    className="mt-1"
                                                    checked={String(option.id) === data.shipping_method_id}
                                                    onChange={() => setData('shipping_method_id', String(option.id))}
                                                />
                                                <span>
                                                    <span className="block font-medium">{option.name}</span>
                                                    {option.description && (
                                                        <span className="text-muted-foreground block whitespace-pre-line">{option.description}</span>
                                                    )}
                                                    {option.pickup && (
                                                        <span className="text-muted-foreground block">
                                                            {[option.pickup.location, option.pickup.address].filter(Boolean).join(', ')}
                                                            {' · '}
                                                            <span
                                                                className={
                                                                    option.pickup.in_stock ? 'text-green-700 dark:text-green-400' : 'text-destructive'
                                                                }
                                                            >
                                                                {option.pickup.in_stock
                                                                    ? t('Everything is in stock here')
                                                                    : t('Not everything is in stock here')}
                                                            </span>
                                                        </span>
                                                    )}
                                                </span>
                                            </span>
                                            <span className="font-medium">{option.price.minor === 0 ? t('Free') : option.price.formatted}</span>
                                        </label>
                                    ))}
                                </div>
                            )}
                            <InputError message={fieldErrors.shipping_method_id} />
                        </section>
                    )}

                    <section className="grid gap-4">
                        <h2 className="text-lg font-semibold">{t('Billing address')}</h2>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={data.billing_same_as_shipping}
                                onCheckedChange={(checked) => setData('billing_same_as_shipping', checked === true)}
                            />
                            {t('Same as the shipping address')}
                        </label>
                        {!data.billing_same_as_shipping && (
                            <AddressFields
                                value={data.billing}
                                onChange={(next) => setData('billing', next)}
                                countries={countries}
                                errors={fieldErrors}
                                prefix="billing."
                                idPrefix="billing"
                            />
                        )}
                    </section>

                    <div className="grid gap-2">
                        <Label htmlFor="payment_method_id">{t('Payment method')}</Label>
                        <select
                            id="payment_method_id"
                            className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                            value={data.payment_method_id}
                            onChange={(event) => setData('payment_method_id', event.target.value)}
                            required
                        >
                            {paymentMethods.map((method) => (
                                <option key={method.id} value={method.id}>
                                    {method.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.payment_method_id} />
                        {paymentMethods.find((method) => String(method.id) === String(data.payment_method_id))?.description && (
                            <p className="text-muted-foreground text-sm">
                                {paymentMethods.find((method) => String(method.id) === String(data.payment_method_id))?.description}
                            </p>
                        )}
                    </div>
                </div>

                <aside className="h-fit rounded-xl border p-6">
                    <h2 className="mb-4 font-semibold">{t('Order summary')}</h2>
                    <ul className="mb-4 space-y-2 text-sm">
                        {cart.items.map((item) => (
                            <li key={item.variant_id} className="flex justify-between gap-4">
                                <span>
                                    {item.title}
                                    {item.variant_label && ` (${item.variant_label})`} × {item.quantity}
                                </span>
                                <span>{item.line_total.formatted}</span>
                            </li>
                        ))}
                    </ul>
                    <TotalsBreakdown totals={delivery.totals} />
                    <Slot name="checkout.before_submit" props={{ cart, totals: delivery.totals }} />
                    <Button type="submit" className="w-full" disabled={processing || (shippingRequired && data.shipping_method_id === '')}>
                        {t('Place order')}
                    </Button>
                    <Button variant="ghost" className="mt-2 w-full" asChild>
                        <Link href={route('cart.index')}>{t('Back to cart')}</Link>
                    </Button>
                </aside>
            </form>
        </StorefrontLayout>
    );
}
