<?php

namespace App\Services;

final class WidgetInstallationService
{
    public function __construct(private KnowledgeIngestionService $ingestion) {}

    /** @return array<string, array{label: string, description: string, steps: list<string>}> */
    public function platforms(): array
    {
        return [
            'wordpress' => [
                'label' => 'WordPress / WooCommerce',
                'description' => 'Add the universal script through a site-wide header/footer code tool.',
                'steps' => ['Open WordPress Admin.', 'Open your site-wide header/footer code tool or plugin.', 'Add the Legatus script before the closing body tag, enable it for the whole site, and save.'],
            ],
            'drupal' => [
                'label' => 'Drupal',
                'description' => 'Add the universal script through the theme or a site-wide asset/module setting.',
                'steps' => ['Open Drupal administration.', 'Use the site-wide asset/module area, or ask the site maintainer to add the script to the active theme.', 'Place it before the closing body tag, clear Drupal cache, and save.'],
            ],
            'shopify' => [
                'label' => 'Shopify',
                'description' => 'Add the universal script once to the active theme layout.',
                'steps' => ['Open Online Store → Themes.', 'Choose Edit code for the active theme and open theme.liquid.', 'Paste the script immediately before </body> and save.'],
            ],
            'wix' => [
                'label' => 'Wix',
                'description' => 'Use Wix Custom Code and apply it to every page.',
                'steps' => ['Open Settings → Custom Code.', 'Add the Legatus script to Body - end.', 'Choose All pages, load once per page, and apply.'],
            ],
            'squarespace' => [
                'label' => 'Squarespace',
                'description' => 'Use site-wide footer code injection.',
                'steps' => ['Open Settings → Advanced → Code Injection.', 'Paste the Legatus script into Footer.', 'Save the changes. Code Injection availability depends on the Squarespace plan.'],
            ],
            'webflow' => [
                'label' => 'Webflow',
                'description' => 'Use the site-wide custom code footer.',
                'steps' => ['Open Site settings → Custom code.', 'Paste the Legatus script in Footer code.', 'Save and publish the site.'],
            ],
            'joomla' => [
                'label' => 'Joomla',
                'description' => 'Add the universal script through a trusted custom-code extension or template.',
                'steps' => ['Open Joomla administration.', 'Use a trusted site-wide custom-code extension or the active template.', 'Add the script before </body>, enable it on all pages, and save.'],
            ],
            'magento' => [
                'label' => 'Magento / Adobe Commerce',
                'description' => 'Add the universal script in the store footer configuration.',
                'steps' => ['Open Content → Design → Configuration.', 'Edit the active store view and open HTML Head / Footer settings.', 'Add the script to the footer scripts area, save, and clear Magento cache.'],
            ],
            'prestashop' => [
                'label' => 'PrestaShop',
                'description' => 'Use a site-wide custom-code module or the active theme footer.',
                'steps' => ['Open the PrestaShop back office.', 'Use a trusted custom-code module or the active theme footer hook.', 'Add the script on all pages, save, and clear cache.'],
            ],
            'opencart' => [
                'label' => 'OpenCart',
                'description' => 'Add the universal script through the active theme or an analytics/custom-code extension.',
                'steps' => ['Open OpenCart administration.', 'Use the active theme footer or a trusted site-wide custom-code extension.', 'Add the script before </body>, save, and refresh modifications/cache.'],
            ],
            'ghost' => [
                'label' => 'Ghost',
                'description' => 'Use Ghost Code Injection.',
                'steps' => ['Open Settings → Advanced → Code injection.', 'Paste the Legatus script into Site Footer.', 'Save the changes.'],
            ],
            'blogger' => [
                'label' => 'Blogger',
                'description' => 'Add the universal script to the theme HTML.',
                'steps' => ['Open Theme → Edit HTML.', 'Find the closing </body> tag.', 'Paste the Legatus script immediately before it and save.'],
            ],
            'other' => [
                'label' => 'Other / Custom website',
                'description' => 'The same universal script works on any website that allows site-wide JavaScript.',
                'steps' => ['Open the website’s global footer, custom-code, or tag-manager area.', 'Paste the Legatus script before the closing body tag and apply it to every page.', 'Publish the website, then return here and run the installation check.'],
            ],
        ];
    }

    public function detect(string $url): string
    {
        $response = $this->ingestion->fetchPublicUrl($url, ['Accept' => 'text/html,application/xhtml+xml'], 12, 1);

        return $this->detectFromHtml($response->body(), $response->headers());
    }

    /** @param array<string, mixed> $headers */
    public function detectFromHtml(string $html, array $headers = []): string
    {
        $haystack = mb_strtolower($html.' '.json_encode($headers));
        $markers = [
            'wordpress' => ['wp-content', 'wp-includes', 'wordpress'],
            'shopify' => ['cdn.shopify.com', 'shopify.theme', 'myshopify.com'],
            'wix' => ['wixstatic.com', 'wix.com website builder', '_wix_browser_sess'],
            'squarespace' => ['static1.squarespace.com', 'squarespace.com', 'squarespace-context'],
            'webflow' => ['data-wf-page', 'data-wf-site', 'webflow.com'],
            'drupal' => ['drupal-settings-json', 'sites/default/files', 'drupal.settings'],
            'joomla' => ['content="joomla!', '/media/system/js/', 'option=com_'],
            'magento' => ['magento_', 'mage/cookies', 'static/version', 'adobe commerce'],
            'prestashop' => ['prestashop', 'modules/ps_'],
            'opencart' => ['route=common/home', 'catalog/view/theme/'],
            'ghost' => ['content="ghost', 'ghost-url', 'ghost.io'],
            'blogger' => ['blogger.com', 'blogspot.com', 'blogger-template'],
        ];

        foreach ($markers as $platform => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $platform;
                }
            }
        }

        return 'other';
    }

    /** @return array{installed: bool, url: string} */
    public function verify(string $url, string $scriptUrl): array
    {
        $response = $this->ingestion->fetchPublicUrl($url, ['Accept' => 'text/html,application/xhtml+xml'], 12, 1);
        $html = html_entity_decode($response->body(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $widgetPath = (string) parse_url($scriptUrl, PHP_URL_PATH);

        return [
            'installed' => str_contains($html, $scriptUrl)
                || ($widgetPath !== '' && str_contains($html, $widgetPath)),
            'url' => $url,
        ];
    }
}
