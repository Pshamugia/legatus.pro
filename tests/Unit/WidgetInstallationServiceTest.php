<?php

namespace Tests\Unit;

use App\Services\KnowledgeIngestionService;
use App\Services\WidgetInstallationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WidgetInstallationServiceTest extends TestCase
{
    #[DataProvider('platformMarkers')]
    public function test_it_detects_major_website_platforms(string $platform, string $html): void
    {
        $service = new WidgetInstallationService($this->createMock(KnowledgeIngestionService::class));

        $this->assertSame($platform, $service->detectFromHtml($html));
    }

    /** @return array<string, array{string, string}> */
    public static function platformMarkers(): array
    {
        return [
            'wordpress' => ['wordpress', '<script src="/wp-content/themes/store/app.js"></script>'],
            'drupal' => ['drupal', '<script type="application/json" data-drupal-selector="drupal-settings-json"></script>'],
            'shopify' => ['shopify', '<script src="https://cdn.shopify.com/theme.js"></script>'],
            'wix' => ['wix', '<script src="https://static.wixstatic.com/app.js"></script>'],
            'squarespace' => ['squarespace', '<script src="https://static1.squarespace.com/app.js"></script>'],
            'webflow' => ['webflow', '<html data-wf-page="page-id">'],
            'joomla' => ['joomla', '<meta name="generator" content="Joomla! - Open Source Content Management">'],
            'magento' => ['magento', '<script src="/static/version123/frontend/app.js"></script>'],
            'prestashop' => ['prestashop', '<body class="prestashop">'],
            'opencart' => ['opencart', '<link href="catalog/view/theme/store/stylesheet.css">'],
            'ghost' => ['ghost', '<meta name="generator" content="Ghost 6">'],
            'blogger' => ['blogger', '<meta content="blogger-template" name="template">'],
            'other' => ['other', '<html><body>Custom website</body></html>'],
        ];
    }
}
