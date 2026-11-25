<?php
declare(strict_types=1);

namespace HTD\AISEO\Core;

use HTD\AISEO\Support\Capabilities;
use HTD\AISEO\Support\Environment;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Handles plugin activation and deactivation safely without external calls or LIVE content writes.
 */
final class Lifecycle {
	public static function activate(): void {
		if (!Environment::isPhpCompatible()) {
			return;
		}
		Capabilities::grantToAdministrator();
		$plugin = Plugin::instance();
		$plugin->migrator()->migrate();
		$plugin->settings()->ensureDefaults();
		$plugin->container()->get(\HTD\AISEO\Queue\CronScheduler::class)->syncWithSettings();
	}

	public static function deactivate(): void {
		\HTD\AISEO\Queue\CronScheduler::clearScheduled();
		if (function_exists('wp_clear_scheduled_hook')) {
			wp_clear_scheduled_hook('htd_ai_seo_cron_heartbeat');
		}
		// Never drop tables or delete data on deactivation.
	}
}
