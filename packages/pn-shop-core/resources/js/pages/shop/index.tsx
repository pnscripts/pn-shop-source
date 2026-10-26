import { PaginationLinks } from '@/components/pagination-links';
import { ProductCard } from '@/components/product-card';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { type Paginated, type ProductCard as ProductCardType } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

type Category = { id: number; title: string; slug: string; children: Category[] };
type Brand = { id: number; name: string; slug: string };
type Facet = { id: number; label: string; values: { id: number; value: string }[] };
type Sort = { key: string; label: string };
type Filters = {
    category: string | null;
    category_path: string[];
    brand: string | null;
    attributes: Record<string, number[]>;
    q?: string;
    sort?: string;
};
type Params = { category?: string | null; brand?: string | null; attributes?: Record<string, number[]>; q?: string; sort?: string };

export default function ShopIndex({
    products,
    categories,
    brands,
    facets,
    filters,
    sorts = [],
}: {
    products: Paginated<ProductCardType>;
    categories: Category[];
    brands: Brand[];
    facets: Facet[];
    filters: Filters;
    sorts?: Sort[];
}) {
    const t = useTranslations();
    const [search, setSearch] = useState(filters.q ?? '');

    // One row of categories per level of the chosen category's path: the roots, then the
    // children of each chosen category on the way down.
    const rows: Category[][] = [categories];
    for (const slug of filters.category_path) {
        const chosen = rows[rows.length - 1].find((category) => category.slug === slug);
        if (!chosen || chosen.children.length === 0) {
            break;
        }
        rows.push(chosen.children);
    }

    /** Shop URL for the current filters with some of them replaced. */
    const shopUrl = (changes: Params) => {
        const next = {
            category: filters.category,
            brand: filters.brand,
            attributes: filters.attributes,
            q: filters.q,
            sort: filters.sort,
            ...changes,
        };
        const filter = Object.fromEntries(Object.entries(next.attributes ?? {}).filter(([, values]) => values.length > 0));

        return route('shop.index', {
            ...(next.category ? { category: next.category } : {}),
            ...(next.brand ? { brand: next.brand } : {}),
            ...(Object.keys(filter).length > 0 ? { filter } : {}),
            ...(next.q ? { q: next.q } : {}),
            ...(next.sort && next.sort !== 'newest' ? { sort: next.sort } : {}),
        });
    };
    const toggleValue = (attributeId: number, valueId: number) => {
        const current = filters.attributes[attributeId] ?? [];
        const values = current.includes(valueId) ? current.filter((id) => id !== valueId) : [...current, valueId];

        return shopUrl({ attributes: { ...filters.attributes, [attributeId]: values } });
    };

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        router.visit(shopUrl({ q: search.trim() }));
    };

    return (
        <StorefrontLayout>
            <Head title={filters.q ? t('Search: :q', { q: filters.q }) : t('Shop')} />
            <div className="mb-6">
                <h1 className="mb-2 text-3xl font-semibold tracking-tight">{t('Shop')}</h1>
                <p className="text-muted-foreground">{t('Active products only. Filter by category if you like.')}</p>
            </div>

            <div className="mb-6 flex flex-wrap items-center gap-3">
                <form role="search" onSubmit={submitSearch} className="flex w-full min-w-0 gap-2 sm:w-auto sm:flex-1">
                    <label htmlFor="shop-search" className="sr-only">
                        {t('Search products')}
                    </label>
                    <input
                        id="shop-search"
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder={t('Search products')}
                        maxLength={100}
                        className="border-input bg-background focus-visible:ring-ring h-9 w-full min-w-0 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none sm:max-w-sm"
                    />
                    <Button type="submit" size="sm" className="h-9 shrink-0">
                        {t('Search')}
                    </Button>
                </form>
                {sorts.length > 0 && (
                    <label className="text-muted-foreground flex w-full items-center gap-2 text-sm sm:w-auto">
                        <span className="shrink-0">{t('Sort by')}</span>
                        <select
                            value={filters.sort ?? 'newest'}
                            onChange={(event) => router.visit(shopUrl({ sort: event.target.value }), { preserveScroll: true })}
                            className="border-input bg-background text-foreground h-9 min-w-0 flex-1 rounded-md border px-2 text-sm sm:flex-none"
                        >
                            {sorts.map((sort) => (
                                <option key={sort.key} value={sort.key}>
                                    {sort.label}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
            </div>

            {rows.map((row, level) => (
                <div key={level} className={`mb-3 flex flex-wrap gap-2 ${level > 0 ? 'pl-4' : ''}`}>
                    {level === 0 && (
                        <Button variant={filters.category ? 'outline' : 'default'} size="sm" asChild>
                            <Link href={shopUrl({ category: null, attributes: {} })}>{t('All')}</Link>
                        </Button>
                    )}
                    {row.map((category) => {
                        const active = filters.category_path.includes(category.slug);

                        return (
                            <Button
                                key={category.id}
                                variant={active ? (level === 0 ? 'default' : 'secondary') : level === 0 ? 'outline' : 'ghost'}
                                size="sm"
                                asChild
                            >
                                <Link href={shopUrl({ category: category.slug, attributes: {} })}>{category.title}</Link>
                            </Button>
                        );
                    })}
                </div>
            ))}

            {brands.length > 0 && (
                <div className="mb-8 flex flex-wrap items-center gap-2">
                    <span className="text-muted-foreground text-sm">{t('Brand')}:</span>
                    {brands.map((brand) => (
                        <Button key={brand.id} variant={filters.brand === brand.slug ? 'default' : 'outline'} size="sm" asChild>
                            <Link href={shopUrl({ brand: filters.brand === brand.slug ? null : brand.slug })}>{brand.name}</Link>
                        </Button>
                    ))}
                </div>
            )}

            {facets.map((facet) => (
                <div key={facet.id} className="mb-3 flex flex-wrap items-center gap-2">
                    <span className="text-muted-foreground text-sm">{facet.label}:</span>
                    {facet.values.map((value) => {
                        const active = (filters.attributes[facet.id] ?? []).includes(value.id);

                        return (
                            <Button key={value.id} variant={active ? 'default' : 'outline'} size="sm" aria-pressed={active} asChild>
                                <Link href={toggleValue(facet.id, value.id)} preserveScroll>
                                    {value.value}
                                </Link>
                            </Button>
                        );
                    })}
                </div>
            ))}

            {products.data.length === 0 ? (
                <p className="text-muted-foreground">
                    {filters.q ? t('No products match ":q".', { q: filters.q }) : t('No products match this filter.')}
                </p>
            ) : (
                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    {products.data.map((product) => (
                        <ProductCard key={product.id} product={product} />
                    ))}
                </div>
            )}

            <div className="mt-8">
                <PaginationLinks paginator={products} />
            </div>
        </StorefrontLayout>
    );
}
