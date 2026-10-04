<?php

namespace PnShop\Inventory;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PnShop\Acl\Models\AdminUser;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Channel\Channels;
use PnShop\Inventory\Exceptions\InsufficientStock;
use PnShop\Inventory\Models\StockLevel;
use PnShop\Inventory\Models\StockLocation;
use PnShop\Inventory\Models\StockMovement;

/**
 * Every stock change goes through here: levels are updated with a conditional statement
 * (never below zero unless the variant allows backorders) and recorded in the ledger.
 *
 * Stock is kept per location. What the storefront can sell is the stock of the active
 * locations that sell online.
 */
final class InventoryService
{
    /** @var array<int, list<int>> channel id => ids of the active locations that sell online there */
    private array $onlineLocationIds = [];

    /**
     * Units that can be sold online now, or null when the variant does not track inventory.
     * Uses the variant's loaded `stockLevels` when available.
     */
    public function available(ProductVariant $variant): ?int
    {
        if (! $variant->track_inventory) {
            return null;
        }

        $online = $this->onlineLocationIds();

        return max(0, (int) $this->levels($variant)
            ->filter(fn (StockLevel $level) => in_array($level->stock_location_id, $online, true))
            ->sum(fn (StockLevel $level) => max(0, $level->available())));
    }

    /**
     * Units available at one location, or null when the variant does not track inventory.
     */
    public function availableAt(ProductVariant $variant, StockLocation $location): ?int
    {
        if (! $variant->track_inventory) {
            return null;
        }

        $level = $this->levels($variant)->firstWhere('stock_location_id', $location->id);

        return max(0, $level?->available() ?? 0);
    }

    /**
     * Units on the shelf at a location (the default one when null), reserved ones included.
     */
    public function onHandAt(ProductVariant $variant, ?StockLocation $location = null): int
    {
        $location ??= StockLocation::default();

        return (int) $this->levels($variant)->firstWhere('stock_location_id', $location->id)?->on_hand;
    }

    /**
     * Units reserved at a location (the default one when null).
     */
    public function reservedAt(ProductVariant $variant, ?StockLocation $location = null): int
    {
        $location ??= StockLocation::default();

        return (int) $this->levels($variant)->firstWhere('stock_location_id', $location->id)?->reserved;
    }

    /**
     * The label of the single stock field in product and variant forms: it edits the
     * default location, so the location is named once there are several.
     */
    public static function stockFieldLabel(): string
    {
        if (StockLocation::query()->count() <= 1) {
            return __('Stock on hand');
        }

        return __('Stock on hand at :location', ['location' => StockLocation::default()->name]);
    }

    /**
     * @return list<int>
     */
    public function onlineLocationIds(): array
    {
        // A channel can sell the stock of some locations only.
        $channels = app(Channels::class);
        $key = $channels->isActive() ? $channels->current()->id : 0;

        return $this->onlineLocationIds[$key] ??= array_values(array_filter(
            array_map('intval', StockLocation::query()->sellingOnline()->pluck('id')->all()),
            fn (int $id) => $channels->allows('stock_location_ids', $id),
        ));
    }

    /** Called when locations change. */
    public function forgetLocations(): void
    {
        $this->onlineLocationIds = [];
    }

    /**
     * Move units from one location to another, recorded in the ledger at both.
     *
     * @throws InsufficientStock when the source has fewer units available.
     */
    public function transfer(ProductVariant $variant, StockLocation $from, StockLocation $to, int $quantity, ?AdminUser $admin = null, ?string $note = null): void
    {
        if ($quantity <= 0 || $from->is($to)) {
            throw new \InvalidArgumentException('A transfer needs a positive quantity and two different locations.');
        }

        DB::transaction(function () use ($variant, $from, $to, $quantity, $admin, $note) {
            // Reserved units stay: only what is available can leave.
            $this->adjust($variant, -$quantity, StockMovementReason::Transfer, $to, $admin, $note, $from, enforceAvailability: true, enforceForAll: true);
            $this->adjust($variant, $quantity, StockMovementReason::Transfer, $from, $admin, $note, $to);
        });
    }

    /**
     * @return Collection<int, StockLevel>
     */
    private function levels(ProductVariant $variant): Collection
    {
        return $variant->relationLoaded('stockLevels') ? $variant->getRelation('stockLevels') : $variant->stockLevels()->get();
    }

    public function canSell(ProductVariant $variant, int $quantity): bool
    {
        $available = $this->available($variant);

        return $available === null || $variant->allow_backorder || $available >= $quantity;
    }

    /**
     * Add (positive) or remove (negative) stock at a location and record the movement.
     *
     * @throws InsufficientStock when removing more than is available and backorders are not allowed.
     */
    public function adjust(
        ProductVariant $variant,
        int $quantity,
        StockMovementReason $reason,
        ?Model $reference = null,
        ?AdminUser $admin = null,
        ?string $note = null,
        ?StockLocation $location = null,
        bool $enforceAvailability = true,
        bool $enforceForAll = false,
    ): StockMovement {
        $location ??= StockLocation::default();

        return DB::transaction(function () use ($variant, $quantity, $reason, $reference, $admin, $note, $location, $enforceAvailability, $enforceForAll) {
            $level = $this->level($variant, $location);

            $update = StockLevel::query()->whereKey($level->id);

            // $enforceForAll: also for variants sold on backorder (a transfer cannot move units that are not there).
            if ($enforceAvailability && $quantity < 0 && (($variant->track_inventory && ! $variant->allow_backorder) || $enforceForAll)) {
                $update->whereRaw('on_hand - reserved >= ?', [-$quantity]);
            }

            if ($update->increment('on_hand', $quantity) === 0) {
                throw new InsufficientStock("Not enough stock for variant {$variant->id}.");
            }

            $variant->unsetRelation('stockLevels');

            return StockMovement::query()->create([
                'product_variant_id' => $variant->id,
                'stock_location_id' => $location->id,
                'quantity' => $quantity,
                'on_hand_after' => (int) StockLevel::query()->whereKey($level->id)->value('on_hand'),
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'admin_user_id' => $admin?->id,
                'note' => $note,
            ]);
        });
    }

    /**
     * Hold units for an order: they stay on hand but are no longer available.
     *
     * @throws InsufficientStock when fewer units are available and backorders are not allowed.
     */
    public function reserve(ProductVariant $variant, int $quantity, ?StockLocation $location = null): void
    {
        $level = $this->level($variant, $location);
        $update = StockLevel::query()->whereKey($level->id);

        if ($variant->track_inventory && ! $variant->allow_backorder) {
            $update->whereRaw('on_hand - reserved >= ?', [$quantity]);
        }

        if ($update->increment('reserved', $quantity) === 0) {
            throw new InsufficientStock("Not enough stock for variant {$variant->id}.");
        }

        $variant->unsetRelation('stockLevels');
    }

    /**
     * Give held units back (never below zero).
     */
    public function release(ProductVariant $variant, int $quantity, ?StockLocation $location = null): void
    {
        if ($quantity <= 0) {
            return;
        }

        $level = $this->level($variant, $location);

        DB::transaction(function () use ($level, $quantity) {
            $released = StockLevel::query()->whereKey($level->id)->where('reserved', '>=', $quantity)->decrement('reserved', $quantity);

            if ($released === 0) {
                StockLevel::query()->whereKey($level->id)->update(['reserved' => 0]);
            }
        });

        $variant->unsetRelation('stockLevels');
    }

    /**
     * Turn held units into a sale: they leave the reservation and the shelf, recorded in the ledger.
     */
    public function commit(ProductVariant $variant, int $quantity, StockMovementReason $reason, ?Model $reference = null, ?StockLocation $location = null): StockMovement
    {
        return DB::transaction(function () use ($variant, $quantity, $reason, $reference, $location) {
            $this->release($variant, $quantity, $location);

            return $this->adjust($variant, -$quantity, $reason, $reference, location: $location, enforceAvailability: false);
        });
    }

    private function level(ProductVariant $variant, ?StockLocation $location): StockLevel
    {
        $location ??= StockLocation::default();

        return StockLevel::query()->firstOrCreate(
            ['product_variant_id' => $variant->id, 'stock_location_id' => $location->id],
            ['on_hand' => 0, 'reserved' => 0],
        );
    }

    /**
     * Set the counted quantity at a location (a stock take), recording the difference.
     */
    public function setOnHand(ProductVariant $variant, int $onHand, ?AdminUser $admin = null, ?string $note = null, ?StockLocation $location = null): ?StockMovement
    {
        $location ??= StockLocation::default();

        $current = (int) StockLevel::query()
            ->where(['product_variant_id' => $variant->id, 'stock_location_id' => $location->id])
            ->value('on_hand');

        if ($onHand === $current) {
            return null;
        }

        // A count is a fact, not a sale: it is recorded even when reservations exceed it.
        return $this->adjust($variant, $onHand - $current, StockMovementReason::Adjustment, admin: $admin, note: $note, location: $location, enforceAvailability: false);
    }
}
