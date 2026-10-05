import { useTranslations } from '@/hooks/use-translations';
import { blockComponent, useExtensionRegistry } from '@/lib/extensions';
import { type ContentBlock } from '@/types';
import { type MouseEvent, useEffect, useState } from 'react';
import { Blocks } from './index';

/** A block as the admin's design page sends it: props are null for a block the storefront would skip. */
type CanvasBlock = { key: string; type: string; label?: string; props: Record<string, unknown> | null };

type EditorMessage = { type: 'pnshop:blocks'; blocks: CanvasBlock[] } | { type: 'pnshop:highlight'; key: string | null };

function fromEditor(event: MessageEvent): EditorMessage | null {
    if (event.origin !== window.location.origin || typeof event.data !== 'object' || event.data === null) {
        return null;
    }

    const type = (event.data as { type?: unknown }).type;

    return type === 'pnshop:blocks' || type === 'pnshop:highlight' ? (event.data as EditorMessage) : null;
}

function toEditor(message: Record<string, unknown>) {
    if (window.parent !== window) {
        window.parent.postMessage(message, window.location.origin);
    }
}

/**
 * The visual editor's canvas: the blocks rendered by the storefront's own components,
 * replaced live by the admin's design page (same origin, postMessage). Clicking a block
 * selects it in the editor; links inside blocks do not navigate.
 */
export function EditableBlocks({ initial }: { initial: ContentBlock[] }) {
    const t = useTranslations();
    const [blocks, setBlocks] = useState<CanvasBlock[] | null>(null);
    const [highlighted, setHighlighted] = useState<string | null>(null);

    // Plugin and theme renderers may register after the first render.
    useExtensionRegistry();

    useEffect(() => {
        const listener = (event: MessageEvent) => {
            const message = fromEditor(event);

            if (message?.type === 'pnshop:blocks') {
                setBlocks(message.blocks);
            } else if (message?.type === 'pnshop:highlight') {
                setHighlighted(message.key);

                if (message.key !== null) {
                    document.querySelector(`[data-block-key="${CSS.escape(message.key)}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        };

        window.addEventListener('message', listener);
        toEditor({ type: 'pnshop:ready' });

        return () => window.removeEventListener('message', listener);
    }, []);

    // Until the editor sends the blocks being edited, the saved ones.
    if (blocks === null) {
        return <Blocks blocks={initial} />;
    }

    const select = (event: MouseEvent, key: string) => {
        event.preventDefault();
        event.stopPropagation();
        setHighlighted(key);
        toEditor({ type: 'pnshop:select', key });
    };

    return (
        <div className="space-y-12" data-testid="editable-blocks">
            {blocks.length === 0 && (
                <p className="text-muted-foreground rounded-lg border border-dashed px-6 py-10 text-center text-sm">
                    {t('Add a block in the editor to start.')}
                </p>
            )}
            {blocks.map((block) => {
                const Component = blockComponent(block.type);

                return (
                    <div
                        key={block.key}
                        data-block-key={block.key}
                        onClickCapture={(event) => select(event, block.key)}
                        className={`relative cursor-pointer rounded-md outline-offset-4 ${
                            highlighted === block.key
                                ? 'outline-primary outline outline-2'
                                : 'hover:outline-primary/50 hover:outline-2 hover:outline-dashed'
                        }`}
                    >
                        {Component && block.props ? (
                            <Component {...block.props} />
                        ) : (
                            <div className="text-muted-foreground rounded-lg border border-dashed px-6 py-8 text-center text-sm">
                                {t(':block: fill it in to see it here.', { block: block.label ?? block.type })}
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
