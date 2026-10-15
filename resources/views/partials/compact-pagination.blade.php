@if($paginator->hasPages())
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $start = max(1, $current - 2);
        $end = min($last, $current + 2);
    @endphp
    <nav class="compact-pagination" data-compact-pagination aria-label="Product pages">
        <p>Showing {{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }} of {{ number_format($paginator->total()) }}</p>
        <div class="compact-pagination__links">
            @if($paginator->onFirstPage())
                <span class="is-disabled" aria-disabled="true">← Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev">← Previous</a>
            @endif

            @if($start > 1)
                <a href="{{ $paginator->url(1) }}">1</a>
                @if($start > 2)<span class="compact-pagination__ellipsis">…</span>@endif
            @endif

            @foreach($paginator->getUrlRange($start, $end) as $page => $url)
                @if($page === $current)
                    <span class="is-current" aria-current="page">{{ $page }}</span>
                @else
                    <a href="{{ $url }}">{{ $page }}</a>
                @endif
            @endforeach

            @if($end < $last)
                @if($end < $last - 1)<span class="compact-pagination__ellipsis">…</span>@endif
                <a href="{{ $paginator->url($last) }}">{{ $last }}</a>
            @endif

            @if($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next →</a>
            @else
                <span class="is-disabled" aria-disabled="true">Next →</span>
            @endif
        </div>
    </nav>
@endif
