@if($canChooseUiLocale ?? false)
    <aside @class(['ui-locale', 'is-sidebar' => ($variant ?? null) === 'sidebar']) aria-label="Interface language">
        <form method="post" action="{{ route('ui-locale.update') }}">
            @csrf
            <input type="hidden" name="locale" value="ka">
            <button type="submit" class="{{ ($uiLocale ?? 'ka') === 'ka' ? 'is-active' : '' }}" lang="ka">{{ trans('locale.short', [], 'ka') }}</button>
        </form>
        <form method="post" action="{{ route('ui-locale.update') }}">
            @csrf
            <input type="hidden" name="locale" value="en">
            <button type="submit" class="{{ ($uiLocale ?? 'ka') === 'en' ? 'is-active' : '' }}" lang="en">EN</button>
        </form>
    </aside>
@endif
