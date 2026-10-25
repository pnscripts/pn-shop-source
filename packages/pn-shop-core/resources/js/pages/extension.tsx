import { Slot } from '@/components/slot';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { slotComponents, useExtensionRegistry } from '@/lib/extensions';
import { Head } from '@inertiajs/react';

type ExtensionPageProps = {
    /** Which plugin screen this is: the page renders the "page.<screen>" slot. */
    screen: string;
    title: string;
    /** Whatever the plugin's controller sends for its screen. */
    data?: Record<string, unknown>;
};

/**
 * A storefront page owned by a plugin: the shop's layout and title, with the plugin's
 * component (registered for the slot "page.<screen>") as the content. Plugins render it
 * with Inertia::render('extension', ['screen' => ..., 'title' => ..., 'data' => [...]]).
 */
export default function ExtensionPage({ screen, title, data = {} }: ExtensionPageProps) {
    const t = useTranslations();
    useExtensionRegistry();

    const slot = `page.${screen}`;

    return (
        <StorefrontLayout>
            <Head title={title} />
            <h1 className="mb-8 text-3xl font-semibold tracking-tight">{title}</h1>
            {slotComponents(slot).length > 0 ? (
                <Slot name={slot} props={data} />
            ) : (
                <p className="text-muted-foreground" role="status">
                    {t('Loading…')}
                </p>
            )}
        </StorefrontLayout>
    );
}
