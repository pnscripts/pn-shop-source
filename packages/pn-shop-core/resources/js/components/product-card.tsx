import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-translations';
import { type ProductCard as ProductCardType } from '@/types';
import { Link } from '@inertiajs/react';

export function ProductCard({ product }: { product: ProductCardType }) {
    const t = useTranslations();

    return (
        <Card className="overflow-hidden py-0">
            <Link href={route('shop.show', product.slug)} className="block">
                <div className="bg-muted aspect-square overflow-hidden">
                    {product.image ? (
                        <img
                            src={product.image.thumb}
                            srcSet={product.image.srcset || undefined}
                            sizes="(min-width: 1280px) 20vw, (min-width: 640px) 40vw, 90vw"
                            alt={product.image.alt}
                            loading="lazy"
                            className="size-full object-cover"
                        />
                    ) : (
                        <div className="text-muted-foreground flex size-full items-center justify-center text-sm">{t('No image')}</div>
                    )}
                </div>
                <CardHeader className="gap-2 px-4 pt-4">
                    {product.category && (
                        <Badge variant="secondary" className="w-fit">
                            {product.category.title}
                        </Badge>
                    )}
                    <CardTitle className="line-clamp-2 text-base">{product.title}</CardTitle>
                </CardHeader>
                <CardContent className="px-4">
                    {product.price ? (
                        <div className="flex items-baseline gap-2">
                            {product.price_from && <span className="text-muted-foreground text-sm">{t('from')}</span>}
                            <span className="font-semibold">{(product.sale_price ?? product.price).formatted}</span>
                            {product.sale_price && <span className="text-muted-foreground text-sm line-through">{product.price.formatted}</span>}
                            {product.price_includes_tax === false && <span className="text-muted-foreground text-xs">{t('excl. tax')}</span>}
                        </div>
                    ) : (
                        <span className="text-muted-foreground text-sm">{t('Sign in to see prices')}</span>
                    )}
                </CardContent>
                <CardFooter className="px-4 pb-4">
                    <span className="text-muted-foreground text-sm">
                        {product.stock === null
                            ? t('In stock')
                            : product.stock > 0
                              ? t(':count in stock', { count: product.stock })
                              : product.backorder
                                ? t('Available to order')
                                : t('Out of stock')}
                    </span>
                </CardFooter>
            </Link>
        </Card>
    );
}
