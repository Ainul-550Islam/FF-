@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination">
        <ul class="pagination">
            {{-- Previous page link --}}
            @if ($paginator->onFirstPage())
                <li>
                    <span class="disabled" aria-disabled="true">&laquo; Previous</span>
                </li>
            @else
                <li>
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev">&laquo; Previous</a>
                </li>
            @endif

            {{-- Next page link --}}
            @if ($paginator->hasMorePages())
                <li>
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next &raquo;</a>
                </li>
            @else
                <li>
                    <span class="disabled" aria-disabled="true">Next &raquo;</span>
                </li>
            @endif
        </ul>
    </nav>
@endif
