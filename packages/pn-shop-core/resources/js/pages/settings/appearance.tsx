import { Head } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import { useTranslations } from '@/hooks/use-translations';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: '/settings/appearance',
    },
];

export default function Appearance() {
    const t = useTranslations();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Appearance settings')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('Appearance settings')} description={t("Choose light, dark or your device's setting")} />
                    <AppearanceTabs />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
