{{--
    The visual page editor: the canvas (the storefront page, in a frame) and the block editor.
    The page sends the rendered blocks after every request ("pnshop-design-blocks"); they are
    passed to the canvas. The canvas reports clicks ("pnshop:select"), which open the block in
    the editor, and the editor reports the block being edited ("pnshop:highlight").
--}}
<x-filament-panels::page>
    {{-- Plain CSS: the admin's stylesheet holds only the utility classes Filament itself uses. --}}
    <style>
        .pnshop-design { display: grid; gap: 1.5rem; }
        .pnshop-design-canvas { display: flex; flex-direction: column; gap: 0.75rem; min-width: 0; }
        .pnshop-design-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; }
        .pnshop-design-stage { display: flex; justify-content: center; overflow: auto; border-radius: 0.75rem; padding: 0.75rem; background: var(--gray-100); }
        .dark .pnshop-design-stage { background: var(--gray-900); }
        .pnshop-design-stage iframe { height: calc(100vh - 14rem); min-height: 32rem; max-width: 100%; border: 0; border-radius: 0.5rem; background: #fff; box-shadow: 0 1px 3px rgb(0 0 0 / 0.15); transition: width 0.2s; }
        .pnshop-design-editor { min-width: 0; }
        .pnshop-design-active { outline: 2px solid var(--primary-500); outline-offset: 2px; border-radius: 0.75rem; }
        @media (min-width: 80rem) {
            .pnshop-design { grid-template-columns: minmax(0, 1fr) 28rem; }
            .pnshop-design-editor { max-height: calc(100vh - 10rem); overflow-y: auto; }
        }
    </style>

    <div
        x-data="{
            blocks: null,
            ready: false,
            viewport: 'desktop',
            widths: { desktop: '100%', tablet: '820px', mobile: '390px' },
            timer: null,
            canvas() { return this.$refs.canvas?.contentWindow },
            // A plain copy: Alpine's reactive proxies cannot be posted.
            send(message) { if (this.ready && this.canvas()) this.canvas().postMessage(JSON.parse(JSON.stringify(message)), window.location.origin) },
            show(blocks) { this.blocks = blocks; this.send({ type: 'pnshop:blocks', blocks }) },
            // Typing in a field: send the pending changes after a short pause; the reply carries the blocks.
            typed() { clearTimeout(this.timer); this.timer = setTimeout(() => $wire.$commit(), 500) },
            focused(event) {
                const item = event.target.closest('[x-sortable-item]');
                if (item) this.send({ type: 'pnshop:highlight', key: item.getAttribute('x-sortable-item') });
            },
            select(key) {
                const item = this.$refs.editor.querySelector(`[x-sortable-item='${CSS.escape(key)}']`);
                if (! item) return;
                item.scrollIntoView({ behavior: 'smooth', block: 'start' });
                item.classList.add('pnshop-design-active');
                setTimeout(() => item.classList.remove('pnshop-design-active'), 1200);
            },
            received(event) {
                if (event.origin !== window.location.origin || event.source !== this.canvas()) return;
                if (event.data?.type === 'pnshop:ready') { this.ready = true; if (this.blocks !== null) this.show(this.blocks) }
                if (event.data?.type === 'pnshop:select') this.select(String(event.data.key));
            },
        }"
        x-init="blocks = @js($this->canvasBlocks())"
        x-on:pnshop-design-blocks.window="show($event.detail.blocks)"
        x-on:message.window="received($event)"
        class="pnshop-design"
    >
        <section class="pnshop-design-canvas">
            <div class="pnshop-design-toolbar">
                <div class="flex gap-1" role="group" aria-label="{{ __('Preview size') }}">
                    @foreach (['desktop' => __('Desktop'), 'tablet' => __('Tablet'), 'mobile' => __('Mobile')] as $size => $label)
                        <x-filament::button
                            size="sm"
                            color="gray"
                            x-on:click="viewport = '{{ $size }}'"
                            x-bind:class="viewport === '{{ $size }}' ? 'pnshop-design-active' : ''"
                            x-bind:aria-pressed="String(viewport === '{{ $size }}')"
                        >
                            {{ $label }}
                        </x-filament::button>
                    @endforeach
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Changes show here at once. Click a block to edit it; save to publish them.') }}</p>
            </div>
            <div class="pnshop-design-stage">
                <iframe
                    x-ref="canvas"
                    src="{{ $this->canvasUrl() }}"
                    title="{{ __('Page preview') }}"
                    x-bind:style="`width: ${widths[viewport]}`"
                ></iframe>
            </div>
        </section>

        <section
            x-ref="editor"
            x-on:input="typed()"
            x-on:change="typed()"
            x-on:focusin="focused($event)"
            class="pnshop-design-editor"
        >
            {{ $this->content }}
        </section>
    </div>
</x-filament-panels::page>
