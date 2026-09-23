<?php

namespace App\Http\Controllers;

use App\Services\MarketingContentService;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 21 — public blog (/blog).
 *
 * Public listings show only published articles inside their window; drafts
 * 404 for everyone except admins, who get an explicit noindex preview (a
 * draft must never be indexable). Canonicals and structured data are
 * deterministic and only emitted for publicly visible pages — title/meta
 * behavior itself stays with the existing Seo helper.
 */
class MarketingArticleController extends Controller
{
    public function __construct(
        protected MarketingContentService $content,
    ) {}

    public function index(Request $request): View
    {
        $activeCategory = $request->integer('category') ?: null;

        $siteName = (string) config('app.name', 'FF Arena');

        app(Seo::class)
            ->title('Blog — '.$siteName)
            ->description('Guides, tournament recaps and product news from the FF Arena team.')
            ->canonical(route('marketing.articles.index'))
            ->indexable();

        return view('marketing.blog.index', [
            'articles' => $this->content->publishedPaginated($activeCategory),
            'categories' => $this->content->categories(),
            'activeCategory' => $activeCategory,
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $article = $this->content->findPublishedBySlug($slug);
        $preview = false;

        if ($article === null) {
            // Draft preview: admins only, and never indexable.
            $article = $this->content->findBySlug($slug);
            $preview = $article !== null && $request->user()?->isAdmin() === true;
        }

        if ($article === null || (! $preview && ! $article->isPubliclyVisible())) {
            abort(404);
        }

        $siteName = (string) config('app.name', 'FF Arena');
        $seo = app(Seo::class)
            ->title(($article->seo_title ?: $article->title).' — '.$siteName)
            ->description((string) ($article->excerpt ?? ''));

        if ($preview) {
            // A draft preview is explicitly non-indexable and carries no
            // canonical/structured data — it is not a real URL yet.
            $seo->indexable(false);
        } else {
            $seo->canonical(route('marketing.articles.show', ['slug' => $article->slug]))
                ->indexable()
                ->ogType('article')
                ->jsonLd([
                    '@context' => 'https://schema.org',
                    '@type' => 'Article',
                    'headline' => $article->title,
                    'description' => (string) ($article->excerpt ?? ''),
                    'datePublished' => $article->published_at?->toIso8601String(),
                    'author' => ['@type' => 'Organization', 'name' => $siteName],
                ]);
        }

        return view('marketing.blog.show', [
            'article' => $article,
            'preview' => $preview,
        ]);
    }
}
