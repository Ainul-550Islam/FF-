@extends('layouts.app')

@section('title', 'Edit: '.$article->title.' — FF Arena Admin')

@section('content')
<section class="container" style="max-width: 820px">
    <div style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center">
        <a href="{{ route('admin.marketing.articles.index') }}" class="btn btn-sm">&larr; Back to Articles</a>
        <a href="{{ route('marketing.articles.show', ['slug' => $article->slug]) }}" class="btn btn-sm" target="_blank">Preview Page &rarr;</a>
    </div>

    <header class="page-head">
        <h1 class="page-title">Edit Article: {{ $article->title }}</h1>
    </header>

    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <ul style="margin: 0; padding-left: 18px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <form method="POST" action="{{ route('admin.marketing.articles.update', $article) }}">
            @csrf
            @method('PUT')

            <div class="field" style="margin-bottom: 16px">
                <label for="title">Title <span style="color: #f87171">*</span></label>
                <input type="text" id="title" name="title" value="{{ old('title', $article->title) }}" required>
            </div>

            <div class="field" style="margin-bottom: 16px">
                <label for="slug">URL Slug <span style="color: #f87171">*</span></label>
                <input type="text" id="slug" name="slug" value="{{ old('slug', $article->slug) }}" required>
                <small class="muted">Accessible at /blog/{{ $article->slug }}</small>
            </div>

            <div class="row" style="display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap">
                <div class="field" style="flex: 1 1 240px; margin: 0">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id">
                        <option value="">Select Category (Optional)</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" {{ old('category_id', $article->category_id) == $category->id ? 'selected' : '' }}>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="flex: 1 1 240px; margin: 0">
                    <label for="status">Publication Status <span style="color: #f87171">*</span></label>
                    <select id="status" name="status" required>
                        <option value="draft" {{ old('status', $article->status) === 'draft' ? 'selected' : '' }}>Draft (Private)</option>
                        <option value="published" {{ old('status', $article->status) === 'published' ? 'selected' : '' }}>Published (Public)</option>
                    </select>
                </div>
            </div>

            <div class="row" style="display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap">
                <div class="field" style="flex: 1 1 240px; margin: 0">
                    <label for="published_at">Publish Start Date</label>
                    <input type="datetime-local" id="published_at" name="published_at" value="{{ old('published_at', $article->published_at?->format('Y-m-d\TH:i')) }}">
                </div>
                <div class="field" style="flex: 1 1 240px; margin: 0">
                    <label for="published_until">Publish End Date (optional)</label>
                    <input type="datetime-local" id="published_until" name="published_until" value="{{ old('published_until', $article->published_until?->format('Y-m-d\TH:i')) }}">
                </div>
            </div>

            <div class="field" style="margin-bottom: 16px">
                <label for="excerpt">Excerpt / Summary</label>
                <textarea id="excerpt" name="excerpt" rows="2">{{ old('excerpt', $article->excerpt) }}</textarea>
            </div>

            <div class="field" style="margin-bottom: 24px">
                <label for="body">Article Body <span style="color: #f87171">*</span></label>
                <textarea id="body" name="body" rows="14" required>{{ old('body', $article->body) }}</textarea>
            </div>

            <div style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 16px; margin-bottom: 24px">
                <h3 style="margin-top: 0">SEO Metadata (Optional)</h3>
                <div class="field" style="margin-bottom: 12px">
                    <label for="seo_title">SEO Title</label>
                    <input type="text" id="seo_title" name="seo_title" value="{{ old('seo_title', $article->seo_title) }}">
                </div>
                <div class="field">
                    <label for="seo_description">SEO Meta Description</label>
                    <input type="text" id="seo_description" name="seo_description" value="{{ old('seo_description', $article->seo_description) }}">
                </div>
            </div>

            <div style="display: flex; gap: 12px">
                <button type="submit" class="btn btn-cyan">Save Changes</button>
                <a href="{{ route('admin.marketing.articles.index') }}" class="btn">Cancel</a>
            </div>
        </form>
    </div>
</section>
@endsection
