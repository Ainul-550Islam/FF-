@extends('layouts.app')

@section('title', 'Manage Blog Articles — FF Arena Admin')

@section('content')
<section class="container" style="max-width: 1040px">
    <header class="page-head" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 class="page-title" style="margin: 0">✍️ Blog & SEO Articles</h1>
        <div>
            <a href="{{ route('admin.marketing.articles.create') }}" class="btn btn-cyan btn-sm">+ Write New Article</a>
        </div>
    </header>

    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error" role="alert">{{ session('error') }}</div>
    @endif

    <div class="card" style="margin-bottom: 20px">
        <div class="row" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px">
            <div style="display: flex; gap: 8px; flex-wrap: wrap">
                <a href="{{ route('admin.marketing.articles.index') }}" class="btn btn-sm {{ empty($status) ? 'btn-cyan' : '' }}">All</a>
                <a href="{{ route('admin.marketing.articles.index', ['status' => 'published']) }}" class="btn btn-sm {{ $status === 'published' ? 'btn-cyan' : '' }}">Published</a>
                <a href="{{ route('admin.marketing.articles.index', ['status' => 'draft']) }}" class="btn btn-sm {{ $status === 'draft' ? 'btn-cyan' : '' }}">Drafts</a>
            </div>

            <form method="GET" action="{{ route('admin.marketing.articles.index') }}" style="display: flex; gap: 8px">
                <select name="category_id" onchange="this.form.submit()" style="padding: 4px 8px; border-radius: 4px">
                    <option value="">All Categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" {{ $categoryId === $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        @if ($articles->isEmpty())
            <p class="muted" style="text-align: center; padding: 24px 0">No articles match your criteria.</p>
        @else
            <div style="overflow-x: auto; margin-top: 16px">
                <table style="width: 100%; border-collapse: collapse">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1)">
                            <th style="padding: 10px">Title & Slug</th>
                            <th style="padding: 10px">Category</th>
                            <th style="padding: 10px">Status</th>
                            <th style="padding: 10px">Publish Window</th>
                            <th style="padding: 10px; text-align: right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($articles as $article)
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05)">
                                <td style="padding: 10px">
                                    <strong>{{ $article->title }}</strong><br>
                                    <code style="font-size: 0.8rem">/blog/{{ $article->slug }}</code>
                                </td>
                                <td style="padding: 10px" class="muted">{{ $article->category?->name ?? 'Uncategorized' }}</td>
                                <td style="padding: 10px">
                                    @if ($article->status === 'published')
                                        <span style="color: #10b981; font-weight: bold">● Published</span>
                                    @else
                                        <span class="muted">○ Draft</span>
                                    @endif
                                </td>
                                <td style="padding: 10px" class="muted" style="font-size: 0.85rem">
                                    {{ $article->published_at?->format('d M Y') ?? '—' }}
                                    @if ($article->published_until)
                                        <br><span style="font-size: 0.8rem">until {{ $article->published_until->format('d M Y') }}</span>
                                    @endif
                                </td>
                                <td style="padding: 10px; text-align: right">
                                    <div style="display: inline-flex; gap: 6px">
                                        <a href="{{ route('marketing.articles.show', ['slug' => $article->slug]) }}" class="btn btn-sm" target="_blank">Preview</a>
                                        <a href="{{ route('admin.marketing.articles.edit', $article) }}" class="btn btn-sm btn-cyan">Edit</a>

                                        @if ($article->status === 'published')
                                            <form method="POST" action="{{ route('admin.marketing.articles.unpublish', $article) }}" style="display:inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm" title="Revert to Draft">Unpublish</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.marketing.articles.publish', $article) }}" style="display:inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm" style="color: #10b981" title="Publish live">Publish</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 16px">
                {{ $articles->withQueryString()->links() }}
            </div>
        @endif
    </div>

    <!-- Category quick creator -->
    <div class="card">
        <h3 style="margin-top: 0">Create Article Category</h3>
        <form method="POST" action="{{ route('admin.marketing.articles.categories.store') }}" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap">
            @csrf
            <div class="field" style="margin: 0; flex: 1 1 200px">
                <label for="cat_name">Name</label>
                <input type="text" id="cat_name" name="name" required placeholder="e.g. Tournament Tactics">
            </div>
            <div class="field" style="margin: 0; flex: 1 1 200px">
                <label for="cat_slug">Slug</label>
                <input type="text" id="cat_slug" name="slug" required placeholder="e.g. tournament-tactics">
            </div>
            <div class="field" style="margin: 0; flex: 2 1 240px">
                <label for="cat_desc">Description (optional)</label>
                <input type="text" id="cat_desc" name="description" placeholder="Short description for SEO listing">
            </div>
            <button type="submit" class="btn btn-cyan btn-sm" style="height: 38px">+ Add Category</button>
        </form>
    </div>
</section>
@endsection
