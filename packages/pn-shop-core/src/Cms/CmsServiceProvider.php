<?php

namespace PnShop\Cms;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use PnShop\Catalog\Models\Brand;
use PnShop\Catalog\Models\Category;
use PnShop\Catalog\Models\Product;
use PnShop\Cms\Blocks\BlockRegistry;
use PnShop\Cms\Blocks\Types\CallToActionBlock;
use PnShop\Cms\Blocks\Types\CategoryGridBlock;
use PnShop\Cms\Blocks\Types\HeroBlock;
use PnShop\Cms\Blocks\Types\HtmlBlock;
use PnShop\Cms\Blocks\Types\ImageBlock;
use PnShop\Cms\Blocks\Types\ProductGridBlock;
use PnShop\Cms\Blocks\Types\RichTextBlock;
use PnShop\Cms\Blocks\Types\VideoBlock;
use PnShop\Cms\Models\Menu;
use PnShop\Cms\Models\MenuItem;
use PnShop\Cms\Models\Page;
use PnShop\Cms\Models\PageRevision;
use PnShop\Cms\Policies\MenuPolicy;
use PnShop\Cms\Policies\PagePolicy;
use PnShop\Cms\Policies\PageRevisionPolicy;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\ModuleServiceProvider;
use PnShop\Settings\SettingDefinition;
use PnShop\Settings\SettingsRegistry;
use PnShop\Settings\SettingsSchema;
use PnShop\Settings\SettingType;

/**
 * CMS pages, content blocks and the block registry.
 */
class CmsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BlockRegistry::class, function ($app) {
            $registry = new BlockRegistry($app);

            foreach ([HeroBlock::class, RichTextBlock::class, ImageBlock::class, ProductGridBlock::class, CategoryGridBlock::class, CallToActionBlock::class, VideoBlock::class, HtmlBlock::class] as $type) {
                $registry->register($type);
            }

            return $registry;
        });

        Relation::morphMap(['page' => Page::class, 'menu' => Menu::class, 'menu_item' => MenuItem::class]);
    }

    protected function permissions(): array
    {
        return [
            new Permission('content.pages.manage', 'Manage pages', 'Content'),
            new Permission('content.menus.manage', 'Manage menus', 'Content'),
            new Permission('cms.html_block', 'Add custom HTML blocks (can run scripts on the storefront)', 'Content'),
        ];
    }

    protected function bootModule(): void
    {
        Gate::policy(Page::class, PagePolicy::class);
        Gate::policy(Menu::class, MenuPolicy::class);
        Gate::policy(MenuItem::class, MenuPolicy::class);
        Gate::policy(PageRevision::class, PageRevisionPolicy::class);

        // Menus link to these; a change to one can change a menu.
        foreach ([Page::class, Category::class, Brand::class, Product::class] as $model) {
            $model::saved(fn () => Menus::flush());
            $model::deleted(fn () => Menus::flush());
        }

        $this->app->make(SettingsRegistry::class)->register(new SettingsSchema(
            'cms',
            'Content',
            new SettingDefinition('revisions_keep', SettingType::Integer, 'Revisions kept per page', default: 30, required: true, rules: ['min:1', 'max:500']),
        ));
    }
}
