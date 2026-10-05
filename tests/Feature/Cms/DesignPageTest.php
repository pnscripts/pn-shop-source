<?php

namespace Tests\Feature\Cms;

use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use PnShop\Cms\Filament\Resources\Pages\PageResource;
use PnShop\Cms\Filament\Resources\Pages\Pages\DesignPage;
use PnShop\Cms\Models\Page;
use Tests\Feature\Admin\AdminTestCase;

/**
 * The visual page editor: the design page (blocks of one language, live canvas blocks,
 * saving) and its canvas (the storefront page in editor mode).
 */
class DesignPageTest extends AdminTestCase
{
    private Page $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->page = Page::factory()->create(['title' => 'About us']);
        $this->page->syncBlocks('body', 'en', [
            ['type' => 'rich_text', 'data' => ['content' => '<p>Hello</p>']],
            ['type' => 'call_to_action', 'data' => ['heading' => 'Shop now', 'button_label' => 'Go', 'button_url' => '/shop']],
        ]);
    }

    public function test_the_design_page_shows_the_blocks_and_renders_them_for_the_canvas(): void
    {
        $this->actingAsAdministrator();

        $component = Livewire::test(DesignPage::class, ['record' => $this->page->getRouteKey()])->assertOk();

        $blocks = $component->instance()->canvasBlocks();
        $this->assertSame(['rich_text', 'call_to_action'], array_column($blocks, 'type'));
        $this->assertSame('Shop now', $blocks[1]['props']['heading']);
        $this->assertSame('Call to action', $blocks[1]['label']);
        $this->assertStringEndsWith('/preview/pages/'.$this->page->id.'/design', $component->instance()->canvasUrl());

        $this->get(PageResource::getUrl('design', ['record' => $this->page]))->assertOk()->assertSee('Page preview');
    }

    public function test_changes_reach_the_canvas_before_saving_and_empty_blocks_keep_their_place(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(DesignPage::class, ['record' => $this->page->getRouteKey()])
            ->set('data.content_blocks.body.en', [
                'a' => ['type' => 'hero', 'data' => ['heading' => '', 'align' => 'left']],
                'b' => ['type' => 'call_to_action', 'data' => ['heading' => 'Spring sale', 'button_label' => 'See', 'button_url' => '/shop']],
            ])
            ->assertDispatched('pnshop-design-blocks', fn (string $name, array $params) => array_column($params['blocks'], 'key') === ['a', 'b']
                && $params['blocks'][0]['props'] === null
                && $params['blocks'][0]['label'] === 'Hero banner'
                && $params['blocks'][1]['props']['heading'] === 'Spring sale');

        // Nothing is saved yet.
        $this->assertSame('Shop now', $this->page->fresh()->blocksFor('body', 'en')[1]['data']['heading']);
    }

    public function test_saving_stores_the_blocks_and_records_a_revision(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(DesignPage::class, ['record' => $this->page->getRouteKey()])
            ->set('data.content_blocks.body.en', [
                'x' => ['type' => 'call_to_action', 'data' => ['heading' => 'New heading', 'button_label' => 'Go', 'button_url' => '/shop']],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $blocks = $this->page->fresh()->blocksFor('body', 'en');
        $this->assertCount(1, $blocks);
        $this->assertSame('New heading', $blocks[0]['data']['heading']);
        $this->assertSame(1, $this->page->revisions()->count());
    }

    public function test_each_language_is_designed_on_its_own(): void
    {
        $this->actingAsAdministrator();

        $component = Livewire::withQueryParams(['locale' => 'bg'])->test(DesignPage::class, ['record' => $this->page->getRouteKey()]);

        $this->assertSame([], $component->instance()->canvasBlocks(), 'Bulgarian has no blocks of its own yet.');
        $this->assertStringContainsString('/bg/preview/pages/'.$this->page->id.'/design', $component->instance()->canvasUrl());

        $component
            ->set('data.content_blocks.body.bg', ['y' => ['type' => 'call_to_action', 'data' => ['heading' => 'Пазарувайте', 'button_label' => 'Към', 'button_url' => '/shop']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Пазарувайте', $this->page->fresh()->blocksFor('body', 'bg')[0]['data']['heading']);
        $this->assertCount(2, $this->page->fresh()->blocksFor('body', 'en'), 'English is untouched.');
    }

    public function test_the_custom_html_rule_applies_in_the_design_page(): void
    {
        $this->actingAsStaff(['content.pages.manage']);

        Livewire::test(DesignPage::class, ['record' => $this->page->getRouteKey()])
            ->set('data.content_blocks.body.en', ['h' => ['type' => 'html', 'data' => ['html' => '<script>steal()</script>']]])
            ->call('save')
            ->assertHasFormErrors(['content_blocks.body.en']);

        $this->assertSame('rich_text', $this->page->fresh()->blocksFor('body', 'en')[0]['type']);
    }

    public function test_only_staff_who_edit_pages_design_them_and_see_the_canvas(): void
    {
        $canvas = '/preview/pages/'.$this->page->id.'/design';

        $this->get($canvas)->assertForbidden();

        $this->actingAsStaff(['catalog.products.view']);
        $this->get(PageResource::getUrl('design', ['record' => $this->page]))->assertForbidden();
        $this->get($canvas)->assertForbidden();

        $this->flushSession(); // A new sign-in, not a second account in the same session.
        $this->actingAsStaff(['content.pages.manage']);
        $this->get($canvas)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('cms/page')
            ->where('editor', true)
            ->where('blocks.1.props.heading', 'Shop now'));
    }
}
