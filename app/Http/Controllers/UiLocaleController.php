<?php

namespace App\Http\Controllers;

use App\Support\WidgetLocaleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UiLocaleController extends Controller
{
    public function update(Request $request, WidgetLocaleResolver $countries): RedirectResponse
    {
        $locale = $request->validate(['locale' => ['required', 'in:ka,en']])['locale'];

        if ($countries->country($request) === 'GE') {
            $request->session()->put('ui_locale', $locale);
        } else {
            $request->session()->forget('ui_locale');
        }

        return back();
    }
}
