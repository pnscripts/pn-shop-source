import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import { useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

export interface OrderReturn {
    id: number;
    number: string;
    status: string;
    status_label: string;
    reason: string;
    staff_note: string | null;
    items: number;
    /** The number of the order the items were exchanged for. */
    exchange_order?: string | null;
    created_at: string | null;
}

export interface Returnable {
    allowed: boolean;
    reason: string | null;
    deadline: string | null;
    items: { id: number; title: string; variant_label: string | null; max: number }[];
    reasons: Record<string, string>;
}

/**
 * The order's return requests and, while the return period lasts, a form to request one.
 */
export function OrderReturns({ orderId, returns, returnable }: { orderId: number; returns: OrderReturn[]; returnable: Returnable }) {
    const t = useTranslations();
    const [open, setOpen] = useState(false);
    const form = useForm<{ items: Record<number, number>; reason: string; note: string }>({
        items: {},
        reason: Object.keys(returnable.reasons)[0] ?? 'other',
        note: '',
    });

    if (returns.length === 0 && !returnable.allowed) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('orders.returns.store', orderId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <section className="mt-8 rounded-xl border p-6" data-testid="order-returns">
            <h2 className="mb-3 font-semibold">{t('Returns')}</h2>

            {returns.length > 0 && (
                <ul className="mb-4 space-y-2 text-sm">
                    {returns.map((item) => (
                        <li key={item.id}>
                            <div className="flex flex-wrap justify-between gap-2">
                                <span>
                                    <span className="font-medium">{item.number}</span> · {t('Items: :count', { count: item.items })} · {item.reason}
                                </span>
                                <span className="font-medium">{item.status_label}</span>
                            </div>
                            {item.exchange_order && (
                                <p className="text-muted-foreground">{t('Exchanged for order :order', { order: item.exchange_order })}</p>
                            )}
                            {item.staff_note && <p className="text-muted-foreground">{item.staff_note}</p>}
                        </li>
                    ))}
                </ul>
            )}

            {returnable.allowed && !open && (
                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                    {returnable.deadline && (
                        <span className="text-muted-foreground">{t('You can return items until :date.', { date: returnable.deadline })}</span>
                    )}
                    <Button variant="outline" onClick={() => setOpen(true)}>
                        {t('Request a return')}
                    </Button>
                </div>
            )}

            {returnable.allowed && open && (
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        {returnable.items.map((item) => (
                            <div key={item.id} className="flex items-center justify-between gap-4 text-sm">
                                <Label htmlFor={`return-item-${item.id}`} className="font-normal">
                                    {item.title}
                                    {item.variant_label && <span className="text-muted-foreground"> · {item.variant_label}</span>}
                                </Label>
                                <Input
                                    id={`return-item-${item.id}`}
                                    type="number"
                                    min={0}
                                    max={item.max}
                                    className="w-20"
                                    value={form.data.items[item.id] ?? 0}
                                    onChange={(event) => form.setData('items', { ...form.data.items, [item.id]: Number(event.target.value) })}
                                />
                            </div>
                        ))}
                        <InputError message={form.errors.items} />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="return-reason">{t('Reason')}</Label>
                        <select
                            id="return-reason"
                            className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                        >
                            {Object.entries(returnable.reasons).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="return-note">{t('Anything we should know? (optional)')}</Label>
                        <Textarea
                            id="return-note"
                            rows={3}
                            maxLength={2000}
                            value={form.data.note}
                            onChange={(event) => form.setData('note', event.target.value)}
                        />
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {t('Send return request')}
                        </Button>
                    </div>
                </form>
            )}

            {!returnable.allowed && returnable.reason && returns.length > 0 && <p className="text-muted-foreground text-sm">{returnable.reason}</p>}
        </section>
    );
}
