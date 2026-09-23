<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A blog / SEO article (Phase 21).
 *
 * Only published rows inside their publication window are publicly rendered
 * and indexable. Bodies are plain text rendered escaped — no raw HTML.
 *
 * @property int $id
 * @property int|null $category_id
 * @property string $slug
 * @property string $title
 * @property string|null $excerpt
 * @property string $body
 * @property string $status
 * @property int|null $author_id
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property Carbon|null $published_at
 * @property Carbon|null $published_until
 */
class MarketingArticle extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
    ];

    protected $fillable = [
        'category_id', 'slug', 'title', 'excerpt', 'body', 'status', 'author_id',
        'seo_title', 'seo_description', 'published_at', 'published_until',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'published_until' => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(MarketingArticleCategory::class, 'category_id');
    }

    /**
     * Whether the article is publicly renderable right now (published and
     * inside its publication window).
     */
    public function isPubliclyVisible(): bool
    {
        if ($this->status !== self::STATUS_PUBLISHED || $this->published_at === null) {
            return false;
        }

        if ($this->published_at->isFuture()) {
            return false;
        }

        if ($this->published_until !== null && $this->published_until->isPast()) {
            return false;
        }

        return true;
    }
}
