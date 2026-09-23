<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A blog / SEO article category (Phase 21).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 */
class MarketingArticleCategory extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name', 'description'];

    public function articles()
    {
        return $this->hasMany(MarketingArticle::class, 'category_id');
    }
}
