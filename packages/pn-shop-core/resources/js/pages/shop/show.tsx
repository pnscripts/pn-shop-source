import InputError from '@/components/input-error';
import { ProductCard } from '@/components/product-card';
import { Slot } from '@/components/slot';
import TextLink from '@/components/text-link';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { type Money, type ProductCard as ProductCardType, type ProductImage } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

type Variant = {
    id: number;
    sku: string | null;
    option_value_ids: number[];
    /** Null when the shop shows prices to signed-in customers only. */
    price: Money | null;
    sale_price: Money | null;
    /** Lower prices from a quantity on, for this customer. */
    tiers?: { min_quantity: number; price: Money }[];
    stock: number | null;
    can_backorder: boolean;
};

type ProductOption = { id: number; name: string; values: { id: number; value: string }[] };

type ProductShow = {
    id: number;
    type: 'simple' | 'variable';
    title: string;
    slug: string;
    description: string | null;
    image: ProductImage | null;
    gallery: ProductImage[];
    options: ProductOption[];
    variants: Variant[];
    default_variant_id: number | null;
    category: { id: number; title: string; slug: string } | null;
    brand: { name: string; slug: string } | null;
    breadcrumbs: { title: string; slug: string }[];
    attributes: { attribute: string | null; value: string | null }[];
    price_includes_tax?: boolean;
    prices_visible?: boolean;
    /** Each unit is a gift card, emailed to the recipient named here (or the buyer) once paid. */
    is_gift_card?: boolean;
};

export default function ShopShow({ product, related }: { product: ProductShow; related: ProductCardType[] }) {
    const t = useTranslations();
    const [shownIndex, setShownIndex] = useState(0);
    const shownImage = product.gallery[shownIndex] ?? product.image;
    const defaultVariant = product.variants.find((variant) => variant.id === product.default_variant_id) ?? product.variants[0];
    const [selection, setSelection] = useState<Record<number, number>>(() =>
        Object.fromEntries(
            product.options.map((option) => [option.id, option.values.find((value) => defaultVariant?.option_value_ids.includes(value.id))?.id ?? 0]),
        ),
    );
    const matches = (variant: Variant, chosen: Record<number, number>) =>
        product.options.every((option) => variant.option_value_ids.includes(chosen[option.id]));
    const variant = product.options.length === 0 ? defaultVariant : product.variants.find((candidate) => matches(candidate, selection));
    const purchasable = variant !== undefined && (variant.stock === null || variant.stock > 0 || variant.can_backorder);

    const { data, setData, post, processing, errors } = useForm({
        variant_id: variant?.id ?? null,
        quantity: 1,
        gift_card: { email: '', name: '', message: '' },
    });
    const recipient = (field: keyof typeof data.gift_card, value: string) => setData('gift_card', { ...data.gift_card, [field]: value });
    const fieldError = (field: string) => (errors as Record<string, string | undefined>)[field];

    const choose = (optionId: number, valueId: number) => {
        const next = { ...selection, [optionId]: valueId };
        setSelection(next);
        setData('variant_id', product.variants.find((candidate) => matches(candidate, next))?.id ?? null);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('cart.store'));
    };

    return (
        <StorefrontLayout>
            <Head title={product.title} />
            <div className="mb-6 text-sm">
                <Link href={route('shop.index')} className="text-muted-foreground hover:text-foreground">
                    {t('Shop')}
                </Link>
                {product.breadcrumbs.map((crumb) => (
                    <span key={crumb.slug}>
                        <span className="text-muted-foreground"> / </span>
                        <Link href={route('shop.index', { category: crumb.slug })} className="text-muted-foreground hover:text-foreground">
                            {crumb.title}
                        </Link>
                    </span>
                ))}
            </div>

            <div className="grid gap-10 lg:grid-cols-2">
                <div className="bg-muted overflow-hidden rounded-xl border">
                    {shownImage ? (
                        <img
                            src={shownImage.url}
                            srcSet={shownImage.srcset || undefined}
                            sizes="(min-width: 1024px) 50vw, 100vw"
                            alt={shownImage.alt}
                            className="aspect-square w-full object-cover"
                        />
                    ) : (
                        <div className="text-muted-foreground flex aspect-square items-center justify-center">{t('No image')}</div>
                    )}
                    {product.gallery.length > 1 && (
                        <div className="bg-background flex gap-2 overflow-x-auto p-2">
                            {product.gallery.map((image, index) => (
                                <button
                                    key={image.id ?? index}
                                    type="button"
                                    onClick={() => setShownIndex(index)}
                                    aria-label={t('Show image :number', { number: index + 1 })}
                                    aria-current={index === shownIndex}
                                    className={`size-16 shrink-0 overflow-hidden rounded-md border-2 ${index === shownIndex ? 'border-primary' : 'border-transparent'}`}
                                >
                                    <img src={image.thumb} alt="" className="size-full object-cover" />
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                <div>
                    <div className="flex flex-wrap gap-2">
                        {product.category && <Badge variant="secondary">{product.category.title}</Badge>}
                        {product.brand && (
                            <Badge variant="outline" asChild>
                                <Link href={route('shop.index', { brand: product.brand.slug })}>{product.brand.name}</Link>
                            </Badge>
                        )}
                    </div>
                    <h1 className="mt-3 mb-4 text-3xl font-semibold tracking-tight">{product.title}</h1>
                    {variant && !variant.price ? (
                        <p className="mb-6">
                            <TextLink href={route('login')}>{t('Sign in to see prices')}</TextLink>
                        </p>
                    ) : variant?.price ? (
                        <div className="mb-6 flex items-baseline gap-3">
                            <span className="text-2xl font-semibold">{(variant.sale_price ?? variant.price).formatted}</span>
                            {variant.sale_price && <span className="text-muted-foreground line-through">{variant.price.formatted}</span>}
                            {product.price_includes_tax === false && <span className="text-muted-foreground text-sm">{t('excl. tax')}</span>}
                        </div>
                    ) : (
                        <p className="text-muted-foreground mb-6">{t('This combination is not available.')}</p>
                    )}
                    {variant && variant.tiers && variant.tiers.length > 0 && (
                        <ul className="text-muted-foreground -mt-4 mb-6 space-y-1 text-sm">
                            {variant.tiers.map((tier) => (
                                <li key={tier.min_quantity}>
                                    {t(':count or more: :price each', { count: tier.min_quantity, price: tier.price.formatted })}
                                </li>
                            ))}
                        </ul>
                    )}
                    <Slot name="product.after_price" props={{ product, variant }} />
                    {product.description && <p className="text-muted-foreground mb-6 whitespace-pre-line">{product.description}</p>}
                    {variant?.sku && <p className="text-muted-foreground mb-4 text-sm">{t('SKU: :sku', { sku: variant.sku })}</p>}

                    {product.options.map((option) => (
                        <fieldset key={option.id} className="mb-4">
                            <legend className="mb-2 text-sm font-medium">{option.name}</legend>
                            <div className="flex flex-wrap gap-2">
                                {option.values.map((value) => {
                                    const available = product.variants.some((candidate) =>
                                        matches(candidate, { ...selection, [option.id]: value.id }),
                                    );

                                    return (
                                        <Button
                                            key={value.id}
                                            type="button"
                                            size="sm"
                                            variant={selection[option.id] === value.id ? 'default' : 'outline'}
                                            aria-pressed={selection[option.id] === value.id}
                                            disabled={!available}
                                            onClick={() => choose(option.id, value.id)}
                                        >
                                            {value.value}
                                        </Button>
                                    );
                                })}
                            </div>
                        </fieldset>
                    ))}

                    {product.attributes.length > 0 && (
                        <dl className="mb-6 grid gap-2 text-sm">
                            {product.attributes.map((item, index) => (
                                <div key={`${item.attribute}-${index}`} className="flex gap-2">
                                    <dt className="text-muted-foreground">{item.attribute}:</dt>
                                    <dd>{item.value ?? '—'}</dd>
                                </div>
                            ))}
                        </dl>
                    )}

                    {product.prices_visible !== false && (
                        <form onSubmit={submit} className="grid max-w-sm gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="quantity">{t('Quantity')}</Label>
                                <Input
                                    id="quantity"
                                    type="number"
                                    min={1}
                                    max={variant?.stock !== null && !variant?.can_backorder ? variant?.stock : undefined}
                                    value={data.quantity}
                                    onChange={(event) => setData('quantity', Number(event.target.value))}
                                    disabled={!purchasable}
                                />
                                <InputError message={errors.quantity ?? errors.variant_id} />
                            </div>
                            {product.is_gift_card && (
                                <fieldset className="grid gap-3 rounded-lg border p-4">
                                    <legend className="px-1 text-sm font-medium">{t('Who is it for?')}</legend>
                                    <p className="text-muted-foreground text-sm">
                                        {t('We email the gift card when your order is paid. Leave the email empty to receive it yourself.')}
                                    </p>
                                    <div className="grid gap-2">
                                        <Label htmlFor="gift_card_email">{t("Recipient's email")}</Label>
                                        <Input
                                            id="gift_card_email"
                                            type="email"
                                            value={data.gift_card.email}
                                            onChange={(event) => recipient('email', event.target.value)}
                                        />
                                        <InputError message={fieldError('gift_card.email')} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="gift_card_name">{t("Recipient's name")}</Label>
                                        <Input
                                            id="gift_card_name"
                                            value={data.gift_card.name}
                                            onChange={(event) => recipient('name', event.target.value)}
                                        />
                                        <InputError message={fieldError('gift_card.name')} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="gift_card_message">{t('Message (optional)')}</Label>
                                        <Textarea
                                            id="gift_card_message"
                                            rows={3}
                                            maxLength={500}
                                            value={data.gift_card.message}
                                            onChange={(event) => recipient('message', event.target.value)}
                                        />
                                        <InputError message={fieldError('gift_card.message')} />
                                    </div>
                                </fieldset>
                            )}
                            <Button type="submit" disabled={processing || !purchasable}>
                                {variant && !purchasable ? t('Out of stock') : t('Add to cart')}
                            </Button>
                            {variant && variant.stock !== null && variant.stock > 0 && (
                                <p className="text-muted-foreground text-sm">{t(':count in stock', { count: variant.stock })}</p>
                            )}
                        </form>
                    )}
                </div>
            </div>
            {related.length > 0 && (
                <section className="mt-16">
                    <h2 className="mb-6 text-xl font-semibold">{t('You may also like')}</h2>
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        {related.map((item) => (
                            <ProductCard key={item.id} product={item} />
                        ))}
                    </div>
                </section>
            )}
        </StorefrontLayout>
    );
}
