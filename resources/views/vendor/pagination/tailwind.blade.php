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

            {{-- Pagination elements --}}
            @foreach ($elements as $element)
                {{-- "Three Dots" separator --}}
                @if (is_string($element))
                    <li><span class="disabled" aria-hidden="true">{{ $element }}</span></li>
                @endif

                {{-- Array of links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li><span aria-current="page" class="current">{{ $page }}</span></li>
                        @else
                            <li><a href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

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

    <p class="pagination-meta">
        Showing {{ $paginator->firstItem() ?? 0 }}&ndash;{{ $paginator->lastItem() ?? 0 }}
        of {{ $paginator->total() }} result{{ $paginator->total() === 1 ? '' : 's' }}
    </p>
@endif
