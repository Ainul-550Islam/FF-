@extends('layouts.app')

@section('title', $article->seo_title ?: ($article->title.' — FF Arena'))

@section('content')
<section class="container" style="max-width: 760px">
    @if ($preview)
        <div class="alert alert-warning" role="status">Draft preview — this page is not public and not indexable.</div>
    @endif

    @if ($article->category)
        <p class="muted" style="margin: 0">{{ $article->category->name }}</p>
    @endif
    <h1>{{ $article->title }}</h1>
    <p class="muted">{{ $article->published_at?->isoFormat('D MMM Y') }}</p>

    @if ($article->excerpt)
        <p style="font-size: 1.05rem">{{ $article->excerpt }}</p>
    @endif

    <div class="card" style="white-space: pre-line; margin-top: 16px">{{ $article->body }}</div>

    <div class="row" style="gap: 10px; margin-top: 20px">
        <a href="{{ route('marketing.articles.index') }}" class="btn btn-sm">← All articles</a>
        <a href="{{ route('tournaments.index') }}" class="btn btn-sm btn-cyan">Browse tournaments</a>
    </div>

    <x-newsletter-signup />
</section>
@endsection
