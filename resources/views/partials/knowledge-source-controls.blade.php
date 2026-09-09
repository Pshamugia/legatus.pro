@if($source)
    <div class="source-controls">
        @if($showProductSync ?? false)
            @php($displayProductCount = (int) ($productCount ?? $source->items_found))
            <span class="source-sync-metrics" aria-label="{{ (int) $source->progress }}% synchronized, {{ number_format($displayProductCount) }} products synchronized">
                <span class="source-sync-progress"><i data-source-progress style="width:{{ (int) $source->progress }}%"></i></span>
                <span><strong data-source-progress-text>{{ (int) $source->progress }}%</strong> synchronized</span>
                <span><strong data-source-items>{{ number_format($displayProductCount) }}</strong> products</span>
            </span>
        @endif
        <span class="pill" data-source-status>{{ $source->status }}</span>
        @if($source->isRefreshable())
            <button class="btn ghost" type="submit" form="sync-source-{{ $source->id }}">↻ Sync</button>
        @endif
        <button class="btn ghost remove-source" type="submit" form="remove-source-{{ $source->id }}">Remove</button>
    </div>
@endif
