import { type Money } from '@/types';

/**
 * The price shown struck through next to a reduced price: the shop's "was" price
 * (prior_price, e.g. the lowest of the last 30 days), or the regular price from servers
 * that do not send one. Null when no reduction is shown.
 */
export function wasPrice(item: { price: Money | null; sale_price: Money | null; prior_price?: Money | null }): Money | null {
    if (!item.sale_price) {
        return null;
    }

    return item.prior_price !== undefined ? item.prior_price : item.price;
}
