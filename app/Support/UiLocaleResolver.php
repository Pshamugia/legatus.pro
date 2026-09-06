<?php

namespace App\Support;

use Illuminate\Http\Request;

class UiLocaleResolver
{
    public function __construct(private readonly WidgetLocaleResolver $countries) {}

    /** @return array{locale: string, can_choose: bool, country: ?string} */
    public function resolve(Request $request): array
    {
        $country = $this->countries->country($request);
        $canChoose = $country === 'GE';
        $locale = $canChoose && $request->hasSession()
            ? $request->session()->get('ui_locale', 'ka')
            : ($canChoose ? 'ka' : 'en');

        if (! in_array($locale, ['ka', 'en'], true)) {
            $locale = $canChoose ? 'ka' : 'en';
        }

        if (! $canChoose && $request->hasSession()) {
            $request->session()->forget('ui_locale');
        }

        return ['locale' => $locale, 'can_choose' => $canChoose, 'country' => $country];
    }
}
