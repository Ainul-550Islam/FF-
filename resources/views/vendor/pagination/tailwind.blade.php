@if ($paginator->hasPages())
<nav role="navigation" aria-label="Pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; flex-wrap: wrap; gap: 12px;">
<div class="text-muted" style="font-size: 13px;">Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }}</div>
<div style="display: flex; gap: 4px;">
@foreach ($elements as $element)
@if (is_string($element))
<span class="btn btn-ghost btn-sm" disabled>{{ $element }}</span>
@endif
@if (is_array($element))
@foreach ($element as $page => $url)
@if ($page == $paginator->currentPage())
<span class="btn btn-primary btn-sm" aria-current="page">{{ $page }}</span>
@else
<a href="{{ $url }}" class="btn btn-secondary btn-sm">{{ $page }}</a>
@endif
@endforeach
@endif
@endforeach
</div>
</nav>
@endif
