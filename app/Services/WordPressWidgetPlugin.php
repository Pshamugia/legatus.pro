<?php

namespace App\Services;

final class WordPressWidgetPlugin
{
    public function archive(string $scriptUrl, string $commerceConnectUrl): string
    {
        $path = 'legatus-ai-sales-assistant/legatus-ai-sales-assistant.php';
        $contents = $this->plugin($scriptUrl, $commerceConnectUrl);
        $crc = (int) sprintf('%u', crc32($contents));
        $size = strlen($contents);
        $nameLength = strlen($path);
        $dosTime = 0;
        $dosDate = 33; // 1980-01-01, the earliest valid ZIP date.

        $local = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $nameLength,
            0,
        ).$path.$contents;

        $central = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $nameLength,
            0,
            0,
            0,
            0,
            0,
            0,
        ).$path;

        $end = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            1,
            1,
            strlen($central),
            strlen($local),
            0,
        );

        return $local.$central.$end;
    }

    public function plugin(string $scriptUrl, string $commerceConnectUrl): string
    {
        $url = var_export($scriptUrl, true);
        $connectUrl = var_export($commerceConnectUrl, true);

        return <<<PHP
<?php
/**
 * Plugin Name: Legatus AI Sales Assistant
 * Description: Installs the business-specific Legatus website chat on every public page.
 * Version: 1.0.0
 * Author: Legatus
 */

defined('ABSPATH') || exit;

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_script('legatus-ai-sales-assistant', {$url}, [], null, true);
    if (function_exists('wp_script_add_data')) {
        wp_script_add_data('legatus-ai-sales-assistant', 'strategy', 'async');
    }
});

add_action('admin_notices', static function (): void {
    if (! class_exists('WooCommerce') || ! current_user_can('manage_woocommerce')) {
        return;
    }

    \$url = wp_nonce_url(admin_url('admin-post.php?action=legatus_connect_woocommerce'), 'legatus_connect_woocommerce');
    echo '<div class="notice notice-info"><p><strong>Legatus chat is active.</strong> '
        .'<a class="button button-primary" href="'.esc_url(\$url).'">Connect or reconnect WooCommerce</a></p></div>';
});

add_action('admin_post_legatus_connect_woocommerce', static function (): void {
    check_admin_referer('legatus_connect_woocommerce');
    if (! class_exists('WooCommerce') || ! current_user_can('manage_woocommerce')) {
        wp_die('You are not allowed to connect this WooCommerce store.');
    }

    \$url = add_query_arg('store_url', home_url('/'), {$connectUrl});
    wp_redirect(\$url);
    exit;
});
PHP;
    }
}
