<?php

namespace App\Http\Middleware;

use App\Support\UiLocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetUiLocale
{
    public function __construct(private readonly UiLocaleResolver $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolved = $this->locales->resolve($request);
        App::setLocale($resolved['locale']);
        View::share('uiLocale', $resolved['locale']);
        View::share('canChooseUiLocale', $resolved['can_choose']);

        $response = $next($request);
        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            if ($resolved['locale'] === 'ka') {
                $response->setContent($this->translateHtml((string) $response->getContent()));
            }
            $response->headers->set('Content-Language', $resolved['locale']);
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-cache');
        }

        return $response;
    }

    private function translateHtml(string $html): string
    {
        $translations = trans('ui', [], 'ka');
        if (! is_array($translations)) {
            return $html;
        }

        uksort($translations, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        $segments = preg_split(
            '/(<(?:script|style|textarea|pre|code)\b[^>]*>.*?<\/(?:script|style|textarea|pre|code)>)/isu',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if (! is_array($segments)) {
            return $html;
        }

        foreach ($segments as $index => $segment) {
            if (preg_match('/^<(?:script|style|textarea|pre|code)\b/iu', $segment) === 1) {
                continue;
            }

            $segment = preg_replace_callback(
                '/>([^<]+)</u',
                fn (array $match): string => '>'.$this->translateValue($match[1], $translations).'<',
                $segment,
            ) ?? $segment;
            $segment = preg_replace_callback(
                '/\b(aria-label|placeholder|title|content)=(\"|\')(.*?)\2/isu',
                fn (array $match): string => $match[1].'='.$match[2].$this->translateValue($match[3], $translations).$match[2],
                $segment,
            ) ?? $segment;
            $segments[$index] = $segment;
        }

        return implode('', $segments);
    }

    /** @param array<string, string> $translations */
    private function translateValue(string $value, array $translations): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || ! array_key_exists($trimmed, $translations)) {
            return $value;
        }

        $offset = strpos($value, $trimmed);

        return substr($value, 0, $offset).$translations[$trimmed].substr($value, $offset + strlen($trimmed));
    }
}
