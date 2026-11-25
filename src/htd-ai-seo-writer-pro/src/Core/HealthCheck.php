<?php
declare(strict_types=1);

namespace HTD\AISEO\Core;

use HTD\AISEO\Support\Environment;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Provides real-time system health metrics without fabricated data.
 */
final class HealthCheck {
	public function __construct(
		private readonly ModuleRegistry $modules,
		private readonly \HTD\AISEO\Settings\SettingsRegistry $settings,
		private readonly \HTD\AISEO\Database\Migrator $migrator,
		private readonly \HTD\AISEO\Queue\QueueEngine $queue
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		$allSettings = $this->settings->all();
		return [
			'plugin_version'     => defined('HTD_AI_SEO_VERSION') ? HTD_AI_SEO_VERSION : '0.2.0-dev',
			'php_version'        => PHP_VERSION,
			'php_compatible'     => Environment::isPhpCompatible(),
			'wp_version'         => Environment::wpVersion(),
			'db_schema_version'  => $this->migrator->currentVersion(),
			'db_target_version'  => defined('HTD_AI_SEO_DB_VERSION') ? HTD_AI_SEO_DB_VERSION : '0.2.0',
			'openssl_available'  => Environment::hasOpenSsl(),
			'woocommerce_active' => Environment::isWooCommerceActive(),
			'rank_math_active'   => Environment::isRankMathActive(),
			'litespeed_detected' => Environment::isLiteSpeed(),
			'wp_read_only_mode'  => (bool) ($allSettings['wp_read_only_mode'] ?? true),
			'emergency_stop'     => (bool) ($allSettings['emergency_stop'] ?? false),
			'autopublish'        => (bool) ($allSettings['allow_autopublish'] ?? false),
			'human_approval'     => (bool) ($allSettings['require_human_approval'] ?? true),
			'queue_counts'       => $this->queue->countsByStatus(),
			'modules'            => $this->modules->all(),
		];
	}
}
