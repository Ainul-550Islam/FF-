@extends('layouts.app')

@section('title', $campaign->seo_title ?: ($campaign->headline.' — '.config('app.name', 'FF Arena')))

@section('content')
@php
    $siteName = config('app.name', 'FF Arena');
    $ctaUrl = $campaign->cta_url ? $campaign->cta_url : route('tournaments.index');
    $ctaUrl .= (str_contains($ctaUrl, '?') ? '&' : '?')
        .'utm_source='.urlencode($campaignKey)
        .'&utm_medium=campaign'
        .'&utm_campaign='.urlencode($campaignKey);
@endphp
<section class="container" style="max-width: 900px">
    <h1>{{ $campaign->headline }}</h1>
    @if ($campaign->subheadline)
        <p class="muted" style="font-size: 1.1rem">{{ $campaign->subheadline }}</p>
    @endif

    @if ($campaign->hero_image)
        <img src="{{ asset($campaign->hero_image) }}" alt="{{ $campaign->headline }}" style="width: 100%; border-radius: 12px; margin: 16px 0">
    @endif

    @if ($campaign->body)
        <div class="card" style="white-space: pre-line">{{ $campaign->body }}</div>
    @endif

    <div class="row" style="gap: 10px; margin: 20px 0; flex-wrap: wrap">
        <a href="{{ $ctaUrl }}" class="btn btn-primary"
           onclick="if (window.ffTrack) { ffTrack('player_cta_click', { campaign: @js($campaignKey) }); }">{{ $campaign->cta_label ?: 'Browse tournaments' }}</a>
        <a href="{{ route('tournaments.index') }}" class="btn btn-cyan">See live tournaments</a>
    </div>

    <x-newsletter-signup :campaignKey="$campaignKey" />
</section>

@php
    app(\App\Support\Seo::class)->jsonLd([
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => $campaign->headline,
        'description' => $campaign->seo_description ?: ($campaign->subheadline ?? $campaign->headline),
        'url' => route('marketing.campaigns.show', ['slug' => $campaign->slug]),
        'publisher' => ['@type' => 'Organization', 'name' => $siteName],
    ]);
@endphp
@endsection
