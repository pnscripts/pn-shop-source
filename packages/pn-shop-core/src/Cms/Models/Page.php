<?php

namespace PnShop\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PnShop\Acl\Models\AdminUser;
use PnShop\Channel\Concerns\LimitedToChannels;
use PnShop\Cms\Concerns\HasContentBlocks;
use PnShop\Cms\Factories\PageFactory;
use PnShop\Cms\PageStatus;
use PnShop\Localization\Concerns\Translatable;
use PnShop\Localization\Contracts\TranslatableModel;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A CMS page: translated title, slug and meta, and a body of content blocks per language.
 *
 * A page is live when it is published, its publish date has come and its unpublish date
 * (if any) has not. Scheduling is just a future publish date.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property PageStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $unpublished_at
 * @property string $template
 * @property bool $is_home
 * @property int|null $author_id
 * @property Carbon|null $updated_at
 */
class Page extends Model implements TranslatableModel
{
    /** @use HasFactory<PageFactory> */
    use HasContentBlocks, HasFactory, LimitedToChannels, LogsActivity, SoftDeletes, Translatable;

    /** Pivot table and key for LimitedToChannels. */
    public const CHANNEL_PIVOT = ['channel_page', 'page_id'];

    public const BODY = 'body';

    /** @var list<string> */
    protected $fillable = ['title', 'slug', 'excerpt', 'meta_title', 'meta_description', 'status', 'published_at', 'unpublished_at', 'template', 'is_home', 'author_id'];

    /** @var list<string> */
    protected array $translatable = ['title', 'slug', 'excerpt', 'meta_title', 'meta_description'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'template' => 'default', 'is_home' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PageStatus::class,
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'is_home' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Page $page): void {
            $base = Str::slug(($page->getAttributes()['slug'] ?? '') ?: ($page->getAttributes()['title'] ?? '')) ?: 'page';
            $slug = $base;

            for ($i = 2; static::query()->withoutGlobalScopes()->withTrashed()->where('slug', $slug)->whereKeyNot($page->getKey())->exists(); $i++) {
                $slug = "{$base}-{$i}";
            }

            $page->setAttribute('slug', $slug);
        });

        // Only one page is the homepage.
        static::saved(function (Page $page): void {
            if ($page->is_home) {
                static::query()->withoutGlobalScopes()->whereKeyNot($page->id)->where('is_home', true)->update(['is_home' => false]);
            }
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', PageStatus::Published)
            ->where(fn (Builder $query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('unpublished_at')->orWhere('unpublished_at', '>', now()))
            ->inChannel();
    }

    public function isLive(): bool
    {
        return $this->status === PageStatus::Published
            && ($this->published_at === null || $this->published_at->isPast())
            && ($this->unpublished_at === null || $this->unpublished_at->isFuture());
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'author_id');
    }

    /**
     * @return HasMany<PageRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->latest('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('content')->logOnly(['title', 'slug', 'status', 'published_at', 'is_home'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected static function newFactory(): PageFactory
    {
        return PageFactory::new();
    }
}
