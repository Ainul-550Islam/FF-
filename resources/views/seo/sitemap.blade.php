<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>{{ url('/') }}</loc><lastmod>{{ now()->toAtomString() }}</lastmod><priority>1.0</priority></url>
    <url><loc>{{ url('/tournaments') }}</loc><lastmod>{{ now()->toAtomString() }}</lastmod><priority>0.8</priority></url>
    @foreach($tournaments ?? [] as $tournament)
    <url><loc>{{ route('tournaments.show',$tournament) }}</loc><lastmod>{{ $tournament->updated_at->toAtomString() }}</lastmod><priority>0.6</priority></url>
    @endforeach
</urlset>
