<?php

namespace PnShop\Cms\Filament\Resources\Pages\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use PnShop\Cms\Blocks\BlockRegistry;
use PnShop\Cms\Filament\ContentEditor;
use PnShop\Cms\Filament\Resources\Pages\PageResource;
use PnShop\Cms\Models\Page;
use PnShop\Cms\PageRevisions;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Language;

/**
 * The visual page editor: the page's blocks in one language next to a canvas showing the
 * page in the storefront's own layout, theme and block components. Every change is shown
 * at once (unsaved); clicking a block on the canvas opens it in the editor. Saving works
 * like the edit page (same block rules, a revision is recorded). The data is the same as
 * the edit page's content tabs.
 */
class DesignPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    protected string $view = 'pnshop::filament.cms.design-page';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** The language being edited. */
    #[Url]
    public ?string $locale = null;

    public function mount(int|string $record): void
    {
        // Before the form is filled: the form edits this language's blocks.
        $localization = app(Localization::class);
        $this->locale = is_string($this->locale) && $localization->isSupported($this->locale) ? $this->locale : $localization->defaultLocale();

        parent::mount($record);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Design: :title', ['title' => (string) $this->getRecord()->getAttribute('title')]);
    }

    public function form(Schema $schema): Schema
    {
        $localization = app(Localization::class);

        return $schema->components([
            ContentEditor::builder('body', (string) $this->locale, $this->locale === $localization->defaultLocale()),
        ]);
    }

    /**
     * The blocks being edited, rendered for the canvas, on every request (typing, adding,
     * moving and removing blocks); the view passes them on. (Events dispatched in dehydrate()
     * are collected too late to be sent.)
     */
    public function rendering(): void
    {
        $this->dispatch('pnshop-design-blocks', blocks: $this->canvasBlocks());
    }

    /**
     * @return list<array{key: string, type: string, label: string, props: array<string, mixed>|null}>
     */
    public function canvasBlocks(): array
    {
        $registry = app(BlockRegistry::class);
        $state = data_get($this->data, "content_blocks.body.{$this->locale}", []);

        return array_map(fn (array $block) => [
            ...$block,
            'label' => $registry->has($block['type']) ? $registry->get($block['type'])->label() : $block['type'],
        ], $registry->renderEach(ContentEditor::fromState(is_array($state) ? $state : [])));
    }

    /** The canvas address: the page in the language being edited. */
    public function canvasUrl(): string
    {
        $localization = app(Localization::class);

        return url($localization->prefix((string) $this->locale).'/preview/pages/'.$this->getRecord()->getKey().'/design');
    }

    protected function getHeaderActions(): array
    {
        $localization = app(Localization::class);

        return [
            ...$localization->languages()->filter(fn (Language $language) => $language->code !== $this->locale)->map(
                fn (Language $language) => Action::make('language_'.$language->code)
                    ->label($language->native_name)
                    ->icon(Heroicon::OutlinedLanguage)
                    ->color('gray')
                    ->url(PageResource::getUrl('design', ['record' => $this->getRecord(), 'locale' => $language->code])),
            )->values()->all(),
            Action::make('edit')
                ->label('Page settings')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->url(PageResource::getUrl('edit', ['record' => $this->getRecord()])),
            PageResource::previewAction(),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    protected function afterSave(): void
    {
        /** @var Page $page */
        $page = $this->getRecord();
        app(PageRevisions::class)->record($page, auth('admin')->user());
    }
}
