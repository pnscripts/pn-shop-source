import { LucideIcon } from 'lucide-react';
import type { Config } from 'ziggy-js';

export interface Auth {
    user: User | null;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface Localization {
    locale: string;
    currency: string;
    languages: { code: string; name: string; url: string; active: boolean }[];
}

export interface MenuItem {
    label: string;
    url: string | null;
    new_tab: boolean;
    children: MenuItem[];
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    cartCount: number;
    menus: { header: MenuItem[]; footer: MenuItem[] };
    flash: {
        success: string | null;
        error: string | null;
    };
    ziggy: Config & { location: string };
    localization: Localization;
    translations: Record<string, string>;
    sidebarOpen: boolean;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

/** Money as sent by the server: formatted in the visitor's language. */
export interface Money {
    amount: string;
    minor: number;
    currency: string;
    formatted: string;
}

export interface ProductImage {
    id: number | null;
    url: string;
    thumb: string;
    srcset: string;
    alt: string;
    width: number | null;
    height: number | null;
}

export interface ProductCard {
    id: number;
    title: string;
    slug: string;
    /** Null when the shop shows prices to signed-in customers only. */
    price: Money | null;
    sale_price: Money | null;
    price_from: boolean;
    /** Whether the prices shown include tax (customer groups can see net prices). */
    price_includes_tax?: boolean;
    image: ProductImage | null;
    stock: number | null;
    /** True when the product can still be ordered with no stock left. */
    backorder?: boolean;
    category: {
        id: number;
        title: string;
        slug: string;
    } | null;
}

export interface CartItem {
    variant_id: number;
    product_id: number;
    title: string;
    slug: string;
    variant_label: string;
    sku: string | null;
    price: Money;
    sale_price: Money | null;
    unit_price: Money;
    image: ProductImage | null;
    stock: number | null;
    quantity: number;
    line_total: Money;
    gift_card?: boolean;
    /** Who receives each card; cards beyond the list go to the buyer. Null on other lines. */
    gift_card_recipients?: { email?: string | null; name?: string | null; message?: string | null }[] | null;
}

export interface TotalLine {
    code: string;
    label: string;
    amount: Money;
    included: boolean;
}

export interface Totals {
    subtotal: Money;
    lines: TotalLine[];
    total: Money;
}

/** Order number and its states, already translated (Order::presentStates). */
export interface OrderStates {
    number: string;
    status: string;
    payment_status: string;
    fulfillment_status: string;
}

/** A content block from the CMS: the type picks the component, props come from the server. */
export interface ContentBlock {
    type: string;
    props: Record<string, unknown>;
}

export interface CartSummary {
    items: CartItem[];
    total_quantity: number;
    total_price: Money;
    final_price: Money;
    totals: Totals;
    coupon: CartCoupon | null;
    /** The customer group's minimum order, while the products fall short of it. */
    minimum_order?: Money | null;
    /** Gift cards entered in the cart, and what each pays. */
    gift_cards?: { id: number; label: string; balance: Money; applied: Money | null }[];
    /** The signed-in customer's store credit, when they have some. */
    store_credit?: { available: Money; used: boolean; applied: Money | null } | null;
    /** What is left to pay after gift cards and store credit. */
    amount_due?: Money | null;
}

export interface CartCoupon {
    code: string;
    valid: boolean;
    applied: boolean;
    message: string | null;
}

export interface Paginated<T> {
    data: T[];
    links: {
        url: string | null;
        label: string;
        active: boolean;
    }[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
}
