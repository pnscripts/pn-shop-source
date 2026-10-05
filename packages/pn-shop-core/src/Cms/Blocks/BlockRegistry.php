<?php

namespace PnShop\Cms\Blocks;

use Filament\Forms\Components\Builder\Block;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Throwable;

/**
 * The block types available to content areas.
 */
final class BlockRegistry
{
    /** @var array<string, BlockType> */
    private array $types = [];

    public function __construct(private Container $container) {}

    /**
     * @param  class-string<BlockType>|BlockType  $type
     */
    public function register(string|BlockType $type): void
    {
        $instance = is_string($type) ? $this->container->make($type) : $type;

        $this->types[$instance->key()] = $instance;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): BlockType
    {
        return $this->types[$key] ?? throw new InvalidArgumentException("Block type [{$key}] is not registered.");
    }

    /**
     * @return array<string, BlockType>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * Filament Builder blocks for every type.
     *
     * @return list<Block>
     */
    public function builderBlocks(): array
    {
        return array_values(array_map(fn (BlockType $type) => Block::make($type->key())
            ->label($type->label())
            ->icon($type->icon())
            ->schema($type->fields()), $this->types));
    }

    /**
     * Props for every block, in order and keyed like the input (the visual editor's canvas):
     * a block that would be skipped on the storefront (empty, unknown type, failing) has
     * null props, so the canvas can show a placeholder in its place.
     *
     * @param  array<array-key, array{type: string, data?: array<string, mixed>|null}>  $blocks
     * @return list<array{key: string, type: string, props: array<string, mixed>|null}>
     */
    public function renderEach(array $blocks): array
    {
        $rendered = [];

        foreach ($blocks as $key => $block) {
            $props = null;

            if ($this->has($block['type'])) {
                try {
                    $props = $this->get($block['type'])->props($block['data'] ?? []);
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $rendered[] = ['key' => (string) $key, 'type' => $block['type'], 'props' => $props];
        }

        return $rendered;
    }

    /**
     * Storefront props for stored blocks; unknown types and blocks that fail to render are skipped.
     *
     * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
     * @return list<array{type: string, props: array<string, mixed>}>
     */
    public function render(array $blocks): array
    {
        $rendered = [];

        foreach ($blocks as $block) {
            if (! $this->has($block['type'])) {
                continue;
            }

            try {
                $props = $this->get($block['type'])->props($block['data']);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($props !== null) {
                $rendered[] = ['type' => $block['type'], 'props' => $props];
            }
        }

        return $rendered;
    }
}
