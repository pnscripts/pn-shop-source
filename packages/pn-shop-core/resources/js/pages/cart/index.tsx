import { CouponForm } from '@/components/coupon-form';
import { BalanceLines, GiftCardForm } from '@/components/gift-card-form';
import { ProductCard } from '@/components/product-card';
import { Slot } from '@/components/slot';
import { TotalsBreakdown } from '@/components/totals-breakdown';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { type CartSummary, type ProductCard as ProductCardType } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

export default function CartIndex({ cart, suggestions }: { cart: CartSummary; suggestions: ProductCardType[] }) {
    const t = useTranslations();

    return (
        <StorefrontLayout>
            <Head title={t('Cart')} />
            <h1 className="mb-6 text-3xl font-semibold tracking-tight">{t('Cart')}</h1>

            {cart.items.length === 0 ? (
                <div className="rounded-xl border px-6 py-10 text-center">
                    <p className="text-muted-foreground mb-4">{t('Your cart is empty.')}</p>
                    <Button asChild>
                        <Link href={route('shop.index')}>{t('Continue shopping')}</Link>
                    </Button>
                </div>
            ) : (
                <div className="grid gap-8 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        <div className="overflow-hidden rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">{t('Product')}</th>
                                        <th className="px-4 py-3 font-medium">{t('Qty')}</th>
                                        <th className="px-4 py-3 font-medium">{t('Total')}</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {cart.items.map((item) => (
                                        <tr key={item.variant_id} className="border-t">
                                            <td className="px-4 py-3">
                                                <div className="font-medium">{item.title}</div>
                                                {item.variant_label && <div className="text-muted-foreground text-xs">{item.variant_label}</div>}
                                                <div className="text-muted-foreground">{item.unit_price.formatted}</div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    max={item.stock ?? undefined}
                                                    defaultValue={item.quantity}
                                                    className="w-20"
                                                    onBlur={(event) => {
                                                        const quantity = Number(event.target.value);
                                                        if (quantity !== item.quantity && quantity >= 1) {
                                                            router.patch(route('cart.update', item.variant_id), { quantity });
                                                        }
                                                    }}
                                                />
                                            </td>
                                            <td className="px-4 py-3">{item.line_total.formatted}</td>
                                            <td className="px-4 py-3 text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => router.delete(route('cart.destroy', item.variant_id))}
                                                >
                                                    {t('Remove')}
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <aside className="h-fit rounded-xl border p-6">
                        <h2 className="mb-4 font-semibold">{t('Summary')}</h2>
                        <div className="mb-2 flex justify-between text-sm">
                            <span>{t('Items')}</span>
                            <span>{cart.total_quantity}</span>
                        </div>
                        <CouponForm coupon={cart.coupon} />
                        <GiftCardForm cart={cart} />
                        <TotalsBreakdown totals={cart.totals} />
                        <BalanceLines
                            lines={[
                                ...(cart.gift_cards ?? [])
                                    .filter((card) => card.applied)
                                    .map((card) => ({ label: card.label, amount: card.applied! })),
                                ...(cart.store_credit?.applied ? [{ label: t('Store credit'), amount: cart.store_credit.applied }] : []),
                            ]}
                            amountDue={cart.amount_due ?? null}
                        />
                        <Slot name="cart.after_totals" props={{ cart }} />
                        {cart.minimum_order ? (
                            <p className="text-muted-foreground text-sm" role="status">
                                {t('The minimum order is :amount. Please add more products.', { amount: cart.minimum_order.formatted })}
                            </p>
                        ) : (
                            <Button className="w-full" asChild>
                                <Link href={route('checkout.create')}>{t('Checkout')}</Link>
                            </Button>
                        )}
                    </aside>
                </div>
            )}
            {suggestions.length > 0 && (
                <section className="mt-16">
                    <h2 className="mb-6 text-xl font-semibold">{t('Customers also bought')}</h2>
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        {suggestions.map((item) => (
                            <ProductCard key={item.id} product={item} />
                        ))}
                    </div>
                </section>
            )}
        </StorefrontLayout>
    );
}
