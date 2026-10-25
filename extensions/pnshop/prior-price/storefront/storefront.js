// Storefront code of the 30-day prior price plugin: a note under a reduced price saying
// what the struck-through price is. A plain ES module using window.PnShop (no build step).
const sdk = window.PnShop;

function PriorPriceNote({ variant }) {
    const { priorPrice } = sdk.inertia.usePage().props;
    const t = sdk.useTranslations();

    if (!priorPrice || !variant || !variant.sale_price || !variant.prior_price) {
        return null;
    }

    return sdk.React.createElement(
        'p',
        { className: 'text-muted-foreground -mt-4 mb-6 text-sm', 'data-plugin': 'pnshop/prior-price' },
        t('Struck-through price: the lowest price of the :days days before the reduction.', { days: priorPrice.days }),
    );
}

sdk.registerSlot('product.after_price', PriorPriceNote);
