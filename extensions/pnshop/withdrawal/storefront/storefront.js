// Storefront code of the withdrawal plugin: the "Withdraw from contract here" link in the
// footer and on order pages, and the two-step withdrawal page. A plain ES module using
// window.PnShop (no build step). Class names are ones the storefront already uses.
const sdk = window.PnShop;
const h = sdk.React.createElement;
const { useState } = sdk.React;

const INPUT =
    'border-input placeholder:text-muted-foreground flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:border-destructive';
const TEXTAREA =
    'border-input placeholder:text-muted-foreground flex min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';
const BUTTON =
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium h-9 px-4 py-2 bg-primary text-primary-foreground shadow-xs hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50';
const OUTLINE =
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium h-9 px-4 py-2 border border-input bg-background shadow-xs hover:bg-accent hover:text-accent-foreground';

function WithdrawLink({ order }) {
    const { withdrawal } = sdk.inertia.usePage().props;
    const t = sdk.useTranslations();

    if (!withdrawal || !withdrawal.url) {
        return null;
    }

    const href = order && order.number ? `${withdrawal.url}?order=${encodeURIComponent(order.number)}` : withdrawal.url;

    return h(
        'a',
        { href, className: 'font-medium underline underline-offset-4', 'data-plugin': 'pnshop/withdrawal' },
        t('Withdraw from contract here'),
    );
}

function FooterLink() {
    return h('div', { className: 'mx-auto px-4 pt-6 text-sm md:max-w-7xl' }, h(WithdrawLink, {}));
}

function OrderLink({ order }) {
    return h('p', { className: 'mt-4 text-sm' }, h(WithdrawLink, { order }));
}

function Field({ id, label, error, hint, children }) {
    return h(
        'div',
        { className: 'grid gap-2' },
        h('label', { htmlFor: id, className: 'text-sm leading-none font-medium' }, label),
        children,
        hint ? h('p', { className: 'text-muted-foreground text-sm' }, hint) : null,
        error ? h('p', { className: 'text-sm text-red-600', role: 'alert' }, error) : null,
    );
}

function Details({ values, bot, urls }) {
    const t = sdk.useTranslations();
    const { errors = {} } = sdk.inertia.usePage().props;
    const [form, setForm] = useState(values);
    const [busy, setBusy] = useState(false);
    const set = (key) => (event) => setForm({ ...form, [key]: event.target.value });
    const input = (key, type, autoComplete) =>
        h('input', {
            id: `withdrawal-${key}`,
            name: key,
            type,
            autoComplete,
            required: true,
            value: form[key],
            onChange: set(key),
            className: INPUT,
            'aria-invalid': errors[key] ? true : undefined,
        });

    const submit = (event) => {
        event.preventDefault();
        sdk.inertia.router.post(
            urls.review,
            { ...form, ...bot },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );
    };

    return h(
        'form',
        { onSubmit: submit, className: 'max-w-xl space-y-6', 'data-plugin': 'pnshop/withdrawal' },
        h(
            'p',
            { className: 'text-muted-foreground' },
            t('Use this form to withdraw from a purchase made in this shop. You will confirm on the next step.'),
        ),
        errors.form ? h('p', { className: 'text-sm text-red-600', role: 'alert' }, errors.form) : null,
        h(Field, { id: 'withdrawal-name', label: t('Your name'), error: errors.name }, input('name', 'text', 'name')),
        h(
            Field,
            {
                id: 'withdrawal-order_number',
                label: t('Order number'),
                error: errors.order_number,
                hint: t('As in your order confirmation, e.g. ORD-000042.'),
            },
            input('order_number', 'text', 'off'),
        ),
        h(Field, { id: 'withdrawal-email', label: t('Email for the confirmation'), error: errors.email }, input('email', 'email', 'email')),
        h(
            Field,
            {
                id: 'withdrawal-items',
                label: t('Products (optional)'),
                error: errors.items,
                hint: t('Leave empty to withdraw from the whole order.'),
            },
            h('textarea', { id: 'withdrawal-items', name: 'items', value: form.items, onChange: set('items'), className: TEXTAREA, maxLength: 2000 }),
        ),
        h('button', { type: 'submit', className: BUTTON, disabled: busy }, t('Continue')),
    );
}

function Confirm({ statement, urls }) {
    const t = sdk.useTranslations();
    const [busy, setBusy] = useState(false);
    const row = (label, value) =>
        h(
            'div',
            { className: 'grid gap-1 sm:grid-cols-3' },
            h('dt', { className: 'text-muted-foreground' }, label),
            h('dd', { className: 'sm:col-span-2' }, value),
        );

    const confirm = () => sdk.inertia.router.post(urls.confirm, {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return h(
        'div',
        { className: 'max-w-xl space-y-6', 'data-plugin': 'pnshop/withdrawal' },
        h('p', null, t('Please check your statement. It is sent only when you confirm.')),
        h(
            'dl',
            { className: 'space-y-3 rounded-md border p-4 text-sm' },
            row(t('Statement'), t('I withdraw from the contract for order :number.', { number: statement.order_number })),
            row(t('Your name'), statement.name),
            row(t('Email for the confirmation'), statement.email),
            row(t('Products'), statement.items || t('The whole order')),
        ),
        h(
            'div',
            { className: 'flex flex-wrap gap-3' },
            h('button', { type: 'button', className: BUTTON, onClick: confirm, disabled: busy }, t('Confirm withdrawal')),
            h(sdk.inertia.Link, { href: urls.edit, className: OUTLINE }, t('Change')),
        ),
    );
}

function Sent({ sent }) {
    const t = sdk.useTranslations();

    return h(
        'div',
        { className: 'max-w-xl space-y-4', role: 'status', 'data-plugin': 'pnshop/withdrawal' },
        h('p', { className: 'text-lg font-medium' }, t('Your withdrawal was sent.')),
        h('p', null, t('Reference: :reference', { reference: sent.reference })),
        h('p', { className: 'text-muted-foreground' }, t('A confirmation with the date and time is on its way to :email.', { email: sent.email })),
    );
}

function WithdrawalPage(props) {
    if (props.step === 'confirm') {
        return h(Confirm, props);
    }

    if (props.step === 'sent') {
        return h(Sent, props);
    }

    return h(Details, props);
}

sdk.registerSlot('footer.top', FooterLink);
sdk.registerSlot('order.after_totals', OrderLink);
sdk.registerSlot('page.withdrawal', WithdrawalPage);
