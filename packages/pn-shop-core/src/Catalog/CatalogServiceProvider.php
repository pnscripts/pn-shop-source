<?php

namespace PnShop\Catalog;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use PnShop\Catalog\Models\Brand;
use PnShop\Catalog\Models\Category;
use PnShop\Catalog\Models\Option;
use PnShop\Catalog\Models\OptionValue;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Models\ProductAttribute;
use PnShop\Catalog\Models\ProductAttributeValue;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Catalog\Policies\AttributePolicy;
use PnShop\Catalog\Policies\BrandPolicy;
use PnShop\Catalog\Policies\CategoryPolicy;
use PnShop\Catalog\Policies\OptionPolicy;
use PnShop\Catalog\Policies\ProductPolicy;
use PnShop\Catalog\Pricing\PriceResolver;
use PnShop\Catalog\Pricing\Stages\PriceListPrice;
use PnShop\Catalog\Pricing\Stages\SalePrice;
use PnShop\Foundation\Extension\Permission;
use PnShop\Foundation\Extension\PipelineRegistry;
use PnShop\Foundation\ModuleServiceProvider;

/**
 * Catalog module: products, categories, attributes and their admin screens.
 */
class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function permissions(): array
    {
        return [
            new Permission('catalog.products.view', 'View products', 'Catalog'),
            new Permission('catalog.products.create', 'Create products', 'Catalog'),
            new Permission('catalog.products.update', 'Edit products', 'Catalog'),
            new Permission('catalog.products.delete', 'Delete products', 'Catalog'),
            new Permission('catalog.categories.manage', 'Manage categories', 'Catalog'),
            new Permission('catalog.brands.manage', 'Manage brands', 'Catalog'),
            new Permission('catalog.options.manage', 'Manage variant options', 'Catalog'),
            new Permission('catalog.attributes.manage', 'Manage attributes', 'Catalog'),
        ];
    }

    public function register(): void
    {
        // One per request or queued job: it knows the customer and keeps their quotes.
        $this->app->scoped(PriceResolver::class);

        Relation::morphMap([
            'product' => Product::class,
            'category' => Category::class,
            'brand' => Brand::class,
            'product_variant' => ProductVariant::class,
            'option' => Option::class,
            'option_value' => OptionValue::class,
            'product_attribute' => ProductAttribute::class,
            'product_attribute_value' => ProductAttributeValue::class,
        ]);
    }

    protected function bootModule(): void
    {
        $pipelines = $this->app->make(PipelineRegistry::class);
        $pipelines->stage(PriceResolver::PIPELINE, SalePrice::class, 100);
        $pipelines->stage(PriceResolver::PIPELINE, PriceListPrice::class, 200);

        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Brand::class, BrandPolicy::class);
        Gate::policy(Option::class, OptionPolicy::class);
        Gate::policy(ProductAttribute::class, AttributePolicy::class);
    }
}
