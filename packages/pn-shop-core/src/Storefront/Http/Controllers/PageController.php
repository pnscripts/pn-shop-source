<?php

namespace PnShop\Storefront\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PnShop\Cms\ContentRenderer;
use PnShop\Cms\Models\Page;
use PnShop\Seo\CatalogSeo;
use PnShop\Seo\Seo;

class PageController extends Controller
{
    public function __construct(private ContentRenderer $content) {}

    /**
     * CMS pages live at /{slug} (in the current language). Registered as the fallback
     * route, so the shop's own routes always win.
     */
    public function show(Request $request): Response
    {
        $slug = trim($request->path(), '/');

        abort_unless(preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1, 404);

        $page = Page::query()->live()->whereTranslated('slug', $slug)->first();

        abort_if($page === null, 404);

        app(CatalogSeo::class)->page($request, $page);

        return $this->render($page);
    }

    /**
     * Staff preview drafts and scheduled pages through a signed link from the admin.
     */
    public function preview(Page $page): Response
    {
        app(Seo::class)->noindex();

        return $this->render($page, preview: ! $page->isLive());
    }

    /**
     * The visual editor's canvas: the page in the storefront's own layout and theme, whose
     * blocks the admin's design page replaces live (unsaved) through postMessage. Staff who
     * may edit the page only.
     */
    public function canvas(Page $page): Response
    {
        $staff = auth('admin')->user();
        abort_unless($staff !== null && $staff->can('update', $page), 403);

        app(Seo::class)->noindex();

        return $this->render($page, preview: ! $page->isLive(), editor: true);
    }

    private function render(Page $page, bool $preview = false, bool $editor = false): Response
    {
        return Inertia::render('cms/page', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'preview' => $preview,
            ],
            'blocks' => $this->content->render($page),
            // The design page's canvas: blocks are selectable and replaced live.
            'editor' => $editor,
        ]);
    }
}
