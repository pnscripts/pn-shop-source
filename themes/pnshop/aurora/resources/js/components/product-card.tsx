import { useTranslations } from '@/hooks/use-translations';
import { wasPrice } from '@/lib/prices';
import { type ProductCard as ProductCardType } from '@/types';
import { Link } from '@inertiajs/react';

/**
 * Aurora's product card: a full-bleed image with the price on a pill, replacing the
 * default theme's card (same path, same props).
 */
export function ProductCard({ product }: { product: ProductCardType }) {
    const t = useTranslations();
    const price = product.sale_price ?? product.price;
    const was = wasPrice(product);

    return (
        <Link
            href={route('shop.show', product.slug)}
            className="group bg-card focus-visible:ring-primary block overflow-hidden rounded-[var(--radius)] border shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg focus-visible:ring-2 focus-visible:outline-none"
            data-theme-card="aurora"
        >
            <div className="bg-muted relative aspect-[4/5] overflow-hidden">
                {product.image ? (
                    <img
                        src={product.image.thumb}
                        srcSet={product.image.srcset || undefined}
                        sizes="(min-width: 1280px) 20vw, (min-width: 640px) 40vw, 90vw"
                        alt={product.image.alt}
                        loading="lazy"
                        className="size-full object-cover transition duration-500 group-hover:scale-105"
                    />
                ) : (
                    <div className="text-muted-foreground flex size-full items-center justify-center text-sm">{t('No image')}</div>
                )}
                {price && (
                    <span className="bg-primary text-primary-foreground absolute bottom-3 left-3 rounded-full px-3 py-1 text-sm font-semibold shadow">
                        {product.price_from && `${t('from')} `}
                        {price.formatted}
                        {was && <span className="ml-1.5 text-xs font-normal line-through opacity-80">{was.formatted}</span>}
                    </span>
                )}
                {was && (
                    <span className="absolute top-3 right-3 rounded-full bg-rose-600 px-2 py-0.5 text-xs font-semibold text-white">{t('Sale')}</span>
                )}
            </div>
            <div className="space-y-1 p-4">
                {product.category && <p className="text-muted-foreground text-xs tracking-wide uppercase">{product.category.title}</p>}
                <h3 className="line-clamp-2 font-medium">{product.title}</h3>
                <p className="text-muted-foreground text-xs">
                    {product.stock === null || product.stock > 0 ? t('In stock') : product.backorder ? t('Available to order') : t('Out of stock')}
                </p>
            </div>
        </Link>
    );
}
