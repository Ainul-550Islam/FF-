@extends('layouts.app')

@section('title', 'Blog &amp; Guides — FF Arena')

@section('content')
<section class="container">
    <h1>Blog &amp; Guides</h1>
    <p class="muted">Free Fire tournament guides, strategy and FF Arena news for the Bangladesh community.</p>

    <div class="row" style="gap: 8px; flex-wrap: wrap; margin: 16px 0">
        <a href="{{ route('marketing.articles.index') }}" class="btn btn-sm @if (! $activeCategory) btn-cyan @endif">All</a>
        @foreach ($categories as $category)
            <a href="{{ route('marketing.articles.index', ['category' => $category->id]) }}"
               class="btn btn-sm @if ($activeCategory === $category->id) btn-cyan @endif">{{ $category->name }}</a>
        @endforeach
    </div>

    @if ($articles->isEmpty())
        <div class="card muted">New guides are on the way — check back soon.</div>
    @else
        <div class="row" style="gap: 16px; flex-wrap: wrap">
            @foreach ($articles as $article)
                <article class="card" style="flex: 1 1 260px; max-width: 340px">
                    @if ($article->category)
                        <p class="muted" style="margin: 0 0 4px; font-size: .8rem">{{ $article->category->name }}</p>
                    @endif
                    <h2 style="margin: 0 0 6px; font-size: 1.1rem">
                        <a href="{{ route('marketing.articles.show', ['slug' => $article->slug]) }}">{{ $article->title }}</a>
                    </h2>
                    @if ($article->excerpt)
                        <p class="muted" style="margin: 0">{{ $article->excerpt }}</p>
                    @endif
                    <p class="muted" style="margin: 10px 0 0; font-size: .8rem">{{ $article->published_at?->isoFormat('D MMM Y') }}</p>
                </article>
            @endforeach
        </div>

        <div style="margin-top: 20px">{{ $articles->links() }}</div>
    @endif
</section>
@endsection
