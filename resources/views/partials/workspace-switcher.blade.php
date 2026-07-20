<details class="workspace-switcher">
    <summary aria-label="Switch active business">
        <span class="workspace-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($navigationBusinessName, 0, 1)) }}</span>
        <span class="workspace-summary-copy">
            <small>Active business</small>
            <strong>{{ $navigationBusinessName }}</strong>
        </span>
        <span class="workspace-chevron" aria-hidden="true">&#8964;</span>
    </summary>

    <div class="workspace-menu" role="menu">
        <span class="workspace-menu-label">Your businesses</span>
        @forelse($navigationWorkspaces as $workspace)
            @php($isCurrentWorkspace = (int) $workspace->id === (int) $navigationWorkspace?->id)
            @if($isCurrentWorkspace)
                <span class="workspace-option is-current" role="menuitem" aria-current="page">
                    <span class="workspace-option-mark" aria-hidden="true">&#10003;</span>
                    <span><strong>{{ $workspace->name }}</strong><small>Currently active</small></span>
                </span>
            @elseif(Illuminate\Support\Facades\Route::has('workspaces.switch'))
                <form method="post" action="{{ route('workspaces.switch', $workspace) }}">
                    @csrf
                    <button class="workspace-option" type="submit" role="menuitem">
                        <span class="workspace-option-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($workspace->name, 0, 1)) }}</span>
                        <span><strong>{{ $workspace->name }}</strong><small>Switch workspace</small></span>
                    </button>
                </form>
            @else
                <span class="workspace-option" role="menuitem">
                    <span class="workspace-option-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($workspace->name, 0, 1)) }}</span>
                    <span><strong>{{ $workspace->name }}</strong><small>Workspace</small></span>
                </span>
            @endif
        @empty
            <span class="workspace-empty">No business workspace is connected yet.</span>
        @endforelse
    </div>
</details>
