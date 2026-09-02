@if($source)
    <div class="source-controls">
        <span class="pill" data-source-status>{{ $source->status }}</span>
        @if($source->isRefreshable())
            <button class="btn ghost" type="submit" form="sync-source-{{ $source->id }}">↻ Sync</button>
        @endif
        <button class="btn ghost remove-source" type="submit" form="remove-source-{{ $source->id }}">Remove</button>
    </div>
@endif
