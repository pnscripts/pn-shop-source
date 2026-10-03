<?php

namespace PnShop\Cms\Blocks\Types;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PnShop\Catalog\Filament\Resources\Categories\CategoryResource;
use PnShop\Catalog\Models\Category;
use PnShop\Catalog\Models\Product;
use PnShop\Catalog\Presenters\ProductCardPresenter;
use PnShop\Cms\Blocks\BlockType;
use PnShop\Cms\Blocks\Concerns\BlockHelpers;

final class ProductGridBlock extends BlockType
{
    use BlockHelpers;

    public function key(): string
    {
        return 'product_grid';
    }

    public function label(): string
    {
        return 'Products';
    }

    public function icon(): string
    {
        return 'heroicon-o-shopping-bag';
    }

    public function fields(): array
    {
        return [
            TextInput::make('heading')->maxLength(160),
            Grid::make(2)->schema([
                Select::make('source')->options([
                    'latest' => 'Newest products',
                    'category' => 'Products in a category',
                    'selected' => 'Chosen products',
                ])->default('latest')->required()->live(),
                TextInput::make('limit')->integer()->minValue(1)->maxValue(24)->default(8)->required(),
            ]),
            Select::make('category_id')->label('Category')
                ->options(fn () => CategoryResource::parentOptions(null))
                ->searchable()
                ->visible(fn (Get $get) => $get('source') === 'category')
                ->required(fn (Get $get) => $get('source') === 'category'),
            Select::make('product_ids')->label('Products')
                ->multiple()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => Product::query()->whereLike('title', '%'.addcslashes($search, '%_\\').'%')->limit(30)->pluck('title', 'id')->all())
                ->getOptionLabelsUsing(fn (array $values) => Product::query()->whereKey($values)->pluck('title', 'id')->all())
                ->visible(fn (Get $get) => $get('source') === 'selected')
                ->required(fn (Get $get) => $get('source') === 'selected'),
            $this->linkField('more_url', '"View all" link'),
        ];
    }

    public function props(array $data): ?array
    {
        $limit = max(1, min(24, (int) ($data['limit'] ?? 8)));
        $query = Product::query()->active()->with(ProductCardPresenter::RELATIONS);

        $products = match ($data['source'] ?? 'latest') {
            'category' => ($category = Category::query()->find((int) ($data['category_id'] ?? 0))) === null
                ? collect()
                : $query->whereHas('categories', fn (Builder $categories) => $categories->whereKey($category->subtreeIds()))->latest()->limit($limit)->get(),
            'selected' => $this->selected($query, array_values(array_map('intval', (array) ($data['product_ids'] ?? []))), $limit),
            default => $query->latest()->limit($limit)->get(),
        };

        if ($products->isEmpty()) {
            return null;
        }

        return [
            'heading' => $this->text($data['heading'] ?? null),
            'products' => ProductCardPresenter::presentMany($products),
            'more_url' => $this->localUrl($data['more_url'] ?? null),
        ];
    }

    /**
     * @param  Builder<Product>  $query
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    private function selected(Builder $query, array $ids, int $limit): Collection
    {
        $found = $query->whereKey($ids)->get()->keyBy('id');

        // Keep the order staff chose.
        return collect($ids)->map(fn (int $id) => $found->get($id))->filter()->take($limit)->values();
    }
}
