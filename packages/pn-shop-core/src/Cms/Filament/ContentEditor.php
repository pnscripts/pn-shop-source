<?php

namespace PnShop\Cms\Filament;

use Closure;
use Filament\Forms\Components\Builder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PnShop\Cms\Blocks\BlockRegistry;
use PnShop\Cms\Blocks\BlockType;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Language;

/**
 * The block editor for a content area: one tab per language, the default language first.
 * A language without blocks shows the default language's blocks on the storefront.
 *
 * Works with any model using HasContentBlocks.
 */
final class ContentEditor
{
    public static function make(string $area = 'body', string $label = 'Content'): Section
    {
        $languages = app(Localization::class)->languages();
        $default = app(Localization::class)->defaultLocale();

        return Section::make($label)
            ->schema([
                Tabs::make("content_{$area}")->tabs($languages->sortByDesc(fn (Language $language) => $language->code === $default)->map(
                    fn (Language $language) => Tab::make($language->native_name)->schema([self::builder($area, $language->code, $language->code === $default)]),
                )->values()->all()),
            ]);
    }

    /**
     * The block editor of one language (the design page edits one language at a time).
     */
    public static function builder(string $area, string $locale, bool $isDefault): Builder
    {
        $registry = app(BlockRegistry::class);
        $staff = auth('admin')->user();

        // Block types with a permission (custom HTML) stay visible so existing blocks render,
        // but staff without the permission cannot add or change them (see the rule below).
        $locked = array_keys(array_filter(
            $registry->all(),
            fn (BlockType $type) => $type->permission() !== null && ! ($staff?->can($type->permission()) ?? false),
        ));

        return Builder::make("content_blocks.{$area}.{$locale}")
            ->hiddenLabel()
            ->blocks($registry->builderBlocks())
            ->blockPickerColumns(3)
            ->collapsible()
            ->cloneable()
            ->reorderableWithButtons()
            ->addActionLabel('Add block')
            ->helperText($isDefault ? null : 'Leave empty to show the default language content.')
            ->dehydrated(false)
            ->afterStateHydrated(function (Builder $component, ?Model $record) use ($area, $locale): void {
                $component->state($record !== null && method_exists($record, 'blocksFor') ? self::toState($record->blocksFor($area, $locale)) : []);
            })
            // Staff without the permission may neither add nor change blocks of a locked type.
            ->rules([fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $area, $locale, $locked): void {
                if ($locked === []) {
                    return;
                }

                $before = $record !== null && method_exists($record, 'blocksFor') ? self::lockedData($record->blocksFor($area, $locale), $locked) : [];

                if (self::lockedData(array_values((array) $value), $locked) !== $before) {
                    $fail(__('You may not add or change custom HTML blocks.'));
                }
            }])
            ->saveRelationshipsUsing(function (Builder $component, Model $record) use ($area, $locale): void {
                if (method_exists($record, 'syncBlocks')) {
                    $record->syncBlocks($area, $locale, array_values((array) $component->getState()));
                }
            });
    }

    /**
     * The blocks of a builder's state, keyed like the state, ready for BlockRegistry: files
     * uploaded but not saved yet are left out (they show once the page is saved).
     *
     * @param  array<array-key, mixed>  $state
     * @return array<string, array{type: string, data: array<string, mixed>}>
     */
    public static function fromState(array $state): array
    {
        $blocks = [];

        foreach ($state as $key => $block) {
            if (is_array($block) && is_string($block['type'] ?? null)) {
                $blocks[(string) $key] = ['type' => $block['type'], 'data' => self::withoutUploads(is_array($block['data'] ?? null) ? $block['data'] : [])];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private static function withoutUploads(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof TemporaryUploadedFile) {
                $data[$key] = null;
            } elseif (is_array($value)) {
                $files = array_filter($value, fn (mixed $item) => $item instanceof TemporaryUploadedFile);
                $data[$key] = $files !== [] && count($files) === count($value) ? null : self::withoutUploads($value);
            }
        }

        return $data;
    }

    /**
     * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
     * @return array<string, array{type: string, data: array<string, mixed>}>
     */
    private static function toState(array $blocks): array
    {
        $state = [];

        foreach ($blocks as $block) {
            $state[(string) Str::uuid()] = ['type' => $block['type'], 'data' => $block['data']];
        }

        return $state;
    }

    /**
     * @param  array<int|string, mixed>  $blocks
     * @param  list<string>  $types
     * @return list<string>
     */
    private static function lockedData(array $blocks, array $types): array
    {
        $data = [];

        foreach ($blocks as $block) {
            if (is_array($block) && in_array($block['type'] ?? null, $types, true)) {
                $data[] = json_encode($block['data'] ?? []) ?: '';
            }
        }

        return $data;
    }
}
