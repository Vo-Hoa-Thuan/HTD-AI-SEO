<?php
/**
 * Plugin Name: HTD AI SEO Writer Pro
 * Plugin URI: https://htd.net.vn
 * Description: Independent, self-hosted AI SEO content, WooCommerce, and Rank Math automation platform managed by HTD.
 * Version: 0.2.0-dev
 * Author: HTD Digital
 * Author URI: https://htd.net.vn
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: htd-ai-seo-writer-pro
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
	if (!function_exists('htd_ai_seo_php_requirement_notice')) {
		function htd_ai_seo_php_requirement_notice(): void {
			echo '<div class="notice notice-error"><p><strong>HTD AI SEO Writer Pro</strong> requires PHP 8.1 or higher. Current PHP version: ' . esc_html(PHP_VERSION) . '.</p></div>';
		}
	}
	if (!function_exists('htd_ai_seo_php_requirement_activation')) {
		function htd_ai_seo_php_requirement_activation(): void {
			if (function_exists('deactivate_plugins')) {
				deactivate_plugins(plugin_basename(__FILE__));
			}
			wp_die(
				'HTD AI SEO Writer Pro requires PHP 8.1 or higher. Current PHP version: ' . esc_html(PHP_VERSION) . '.',
				'PHP Version Requirement'
			);
		}
	}
	register_activation_hook(__FILE__, 'htd_ai_seo_php_requirement_activation');
	add_action('admin_notices', 'htd_ai_seo_php_requirement_notice');
	return;
}

define('HTD_AI_SEO_VERSION', '0.2.0-dev');
define('HTD_AI_SEO_DB_VERSION', '0.2.0');
define('HTD_AI_SEO_FILE', __FILE__);
define('HTD_AI_SEO_PATH', plugin_dir_path(__FILE__));
define('HTD_AI_SEO_URL', plugin_dir_url(__FILE__));
define('HTD_AI_SEO_BASENAME', plugin_basename(__FILE__));

// PSR-4 compatible autoloader (no Composer runtime required on shared hosting).
spl_autoload_register(static function (string $class): void {
	$prefix = 'HTD\\AISEO\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}
	$relative = substr($class, strlen($prefix));
	$file = HTD_AI_SEO_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
	if (is_file($file) && is_readable($file)) {
		require_once $file;
	}
});

register_activation_hook(__FILE__, [\HTD\AISEO\Core\Lifecycle::class, 'activate']);
register_deactivation_hook(__FILE__, [\HTD\AISEO\Core\Lifecycle::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
	\HTD\AISEO\Core\Plugin::instance()->boot();
});
