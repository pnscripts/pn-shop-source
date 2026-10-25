// Storefront code of the Econt plugin: the office picker at checkout and the chosen
// office on the order page. A plain ES module using window.PnShop (no build step).
const sdk = window.PnShop;
const h = sdk.React.createElement;
const { useEffect, useState } = sdk.React;

const INPUT =
    'border-input placeholder:text-muted-foreground flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function OfficePicker({ shippingMethodId, shipping }) {
    const { econt } = sdk.inertia.usePage().props;
    const t = sdk.useTranslations();
    const postcode = (shipping && shipping.postcode) || '';
    const town = (shipping && shipping.city) || '';
    const [search, setSearch] = useState('');
    const [offices, setOffices] = useState([]);
    const [selected, setSelected] = useState(null);
    const [state, setState] = useState('idle');
    const active = econt && econt.office_method_ids.includes(String(shippingMethodId || ''));
    const officesUrl = econt ? econt.offices_url : '';

    useEffect(() => {
        if (!active) {
            return undefined;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            setState('loading');
            const params = new URLSearchParams({ postcode, city: town, search });
            fetch(`${officesUrl}?${params}`, { headers: { Accept: 'application/json' }, signal: controller.signal })
                .then((response) => response.json().then((body) => ({ ok: response.ok, body })))
                .then(({ ok, body }) => {
                    setOffices(body.offices || []);
                    setSelected(body.selected || null);
                    setState(ok ? 'ready' : 'error');
                })
                .catch((error) => error.name !== 'AbortError' && setState('error'));
        }, 300);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [active, officesUrl, postcode, town, search]);

    if (!active) {
        return null;
    }

    const choose = (code) => {
        setSelected(code);
        fetch(econt.choose_url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify({ code }),
        }).then((response) => !response.ok && setState('error'));
    };

    return h(
        'fieldset',
        { className: 'mb-4 space-y-3 rounded-lg border p-3 text-sm', 'data-plugin': 'pnshop/econt' },
        h('legend', { className: 'px-1 font-medium' }, t('Econt office for delivery')),
        h('input', {
            type: 'search',
            className: INPUT,
            placeholder: t('Search by office name or address'),
            'aria-label': t('Search by office name or address'),
            value: search,
            onChange: (event) => setSearch(event.target.value),
        }),
        state === 'loading' ? h('p', { className: 'text-muted-foreground', role: 'status' }, t('Loading…')) : null,
        state === 'error'
            ? h(
                  'p',
                  { className: 'text-sm text-red-600', role: 'alert' },
                  t('Econt offices cannot be loaded right now. Please try again in a moment.'),
              )
            : null,
        state === 'ready' && offices.length === 0
            ? h(
                  'p',
                  { className: 'text-muted-foreground' },
                  postcode ? t('No Econt office found. Try another search.') : t('Enter your postcode to see the offices near you.'),
              )
            : null,
        h(
            'div',
            { className: 'grid max-h-64 gap-2 overflow-y-auto', role: 'radiogroup', 'aria-label': t('Econt office for delivery') },
            offices.map((office) =>
                h(
                    'label',
                    {
                        key: office.code,
                        className: `flex cursor-pointer items-start gap-2 rounded-md border p-2 ${office.code === selected ? 'border-primary ring-primary ring-1' : ''}`,
                    },
                    h('input', {
                        type: 'radio',
                        name: 'econt_office_code',
                        value: office.code,
                        checked: office.code === selected,
                        onChange: () => choose(office.code),
                    }),
                    h(
                        'span',
                        null,
                        h('span', { className: 'block font-medium' }, office.name),
                        h('span', { className: 'text-muted-foreground block' }, office.address),
                    ),
                ),
            ),
        ),
    );
}

function OrderOffice() {
    const { econtOffice } = sdk.inertia.usePage().props;
    const t = sdk.useTranslations();

    if (!econtOffice) {
        return null;
    }

    return h(
        'p',
        { className: 'mt-4 text-sm', 'data-plugin': 'pnshop/econt' },
        h('span', { className: 'font-medium' }, t('Delivery to Econt office:') + ' '),
        `${econtOffice.office_name}${econtOffice.office_address ? `, ${econtOffice.office_address}` : ''}`,
    );
}

sdk.registerSlot('checkout.before_submit', OfficePicker);
sdk.registerSlot('order.after_totals', OrderOffice);
