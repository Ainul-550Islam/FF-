<?php

namespace App\Http\Requests;

use App\Models\MarketingArticle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMarketingArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $article = $this->route('article');
        $articleId = $article instanceof MarketingArticle ? $article->id : $article;

        return [
            'title' => 'required|string|max:200',
            'slug' => [
                'required',
                'string',
                'max:200',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('marketing_articles', 'slug')->ignore($articleId),
            ],
            'category_id' => 'nullable|integer|exists:marketing_article_categories,id',
            'excerpt' => 'nullable|string|max:500',
            'body' => 'required|string',
            'seo_title' => 'nullable|string|max:100',
            'seo_description' => 'nullable|string|max:200',
            'status' => 'required|in:'.implode(',', MarketingArticle::STATUSES),
            'published_at' => 'nullable|date',
            'published_until' => 'nullable|date|after:published_at',
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug must contain only lowercase alphanumeric characters and hyphens.',
            'slug.unique' => 'An article with this slug already exists.',
            'published_until.after' => 'The publish end date must be after the start date.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $this->merge([
                'slug' => mb_strtolower(trim((string) $this->input('slug'))),
            ]);
        }
    }

    public function articleData(): array
    {
        return [
            'title' => trim((string) $this->input('title')),
            'slug' => mb_strtolower(trim((string) $this->input('slug'))),
            'category_id' => $this->filled('category_id') ? (int) $this->input('category_id') : null,
            'excerpt' => $this->filled('excerpt') ? trim((string) $this->input('excerpt')) : null,
            'body' => (string) $this->input('body'),
            'seo_title' => $this->filled('seo_title') ? trim((string) $this->input('seo_title')) : null,
            'seo_description' => $this->filled('seo_description') ? trim((string) $this->input('seo_description')) : null,
            'status' => (string) $this->input('status', MarketingArticle::STATUS_DRAFT),
            'published_at' => $this->filled('published_at') ? (string) $this->input('published_at') : null,
            'published_until' => $this->filled('published_until') ? (string) $this->input('published_until') : null,
        ];
    }
}
