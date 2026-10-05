import { Blocks } from '@/components/blocks';
import { EditableBlocks } from '@/components/blocks/editable-blocks';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { type ContentBlock } from '@/types';
import { Head } from '@inertiajs/react';

type Page = { id: number; title: string; excerpt: string | null; preview: boolean };

export default function CmsPage({ page, blocks, editor = false }: { page: Page; blocks: ContentBlock[]; editor?: boolean }) {
    const t = useTranslations();
    const startsWithHero = blocks[0]?.type === 'hero';

    return (
        <StorefrontLayout>
            <Head title={page.title} />
            {page.preview && !editor && (
                <p className="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                    {t('Preview: this page is not published.')}
                </p>
            )}
            {!startsWithHero && <h1 className="mb-8 text-3xl font-semibold tracking-tight">{page.title}</h1>}
            {/* In the admin's design page, the blocks being edited replace these live. */}
            {editor ? <EditableBlocks initial={blocks} /> : <Blocks blocks={blocks} />}
        </StorefrontLayout>
    );
}
