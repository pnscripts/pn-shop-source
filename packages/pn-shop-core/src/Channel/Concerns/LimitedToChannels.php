<?php

namespace PnShop\Channel\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PnShop\Channel\Channels;
use PnShop\Channel\Models\Channel;

/**
 * A record that can be limited to channels. Without any, it is visible in every channel.
 *
 * Storefront scopes (active(), live()) call inChannel(), which only filters while a
 * storefront channel is active: the admin panel and the console see everything.
 *
 * The model declares its pivot table and key with CHANNEL_PIVOT = [table, foreign key].
 */
trait LimitedToChannels
{
    /**
     * @return BelongsToMany<Channel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, self::CHANNEL_PIVOT[0], self::CHANNEL_PIVOT[1]);
    }

    /**
     * Only records visible in the channel (the active one when none is given).
     *
     * @param  Builder<static>  $query
     */
    public function scopeInChannel(Builder $query, ?Channel $channel = null): void
    {
        $channels = app(Channels::class);

        if ($channel === null && ! $channels->isActive()) {
            return;
        }

        $id = ($channel ?? $channels->current())->id;

        $query->where(fn (Builder $query) => $query
            ->whereDoesntHave('channels')
            ->orWhereHas('channels', fn (Builder $channels) => $channels->whereKey($id)));
    }

    public function isVisibleInChannel(?Channel $channel = null): bool
    {
        $channels = app(Channels::class);

        if ($channel === null && ! $channels->isActive()) {
            return true;
        }

        $ids = $this->relationLoaded('channels') ? $this->getRelation('channels')->modelKeys() : $this->channels()->pluck('channels.id')->all();

        return $ids === [] || in_array(($channel ?? $channels->current())->id, $ids, true);
    }
}
