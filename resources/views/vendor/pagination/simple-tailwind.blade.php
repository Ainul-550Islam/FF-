@if ($paginator->hasPages())
<nav style="display: flex; justify-content: space-between; margin-top: 16px;">
@if ($paginator->onFirstPage())
<span class="btn btn-ghost btn-sm" disabled>Previous</span>
@else
<a href="{{ $paginator->previousPageUrl() }}" class="btn btn-secondary btn-sm">Previous</a>
@endif
@if ($paginator->hasMorePages())
<a href="{{ $paginator->nextPageUrl() }}" class="btn btn-secondary btn-sm">Next</a>
@else
<span class="btn btn-ghost btn-sm" disabled>Next</span>
@endif
</nav>
@endif
