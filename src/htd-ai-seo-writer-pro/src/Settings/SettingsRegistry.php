<?php
declare(strict_types=1);

namespace HTD\AISEO\Settings;

use HTD\AISEO\Support\Capabilities;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Typed settings registry enforcing safe defaults.
 */
final class SettingsRegistry {
	public const OPTION_NAME = 'htd_ai_seo_settings';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		return [
			'wp_read_only_mode' => [
				'type'        => 'bool',
				'default'     => true,
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'Block all LIVE WordPress post, product, taxonomy, and SEO metadata mutations.',
			],
			'allow_autopublish' => [
				'type'        => 'bool',
				'default'     => false,
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'Allow automatic publishing without manual publish action.',
			],
			'allow_product_update' => [
				'type'        => 'bool',
				'default'     => false,
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'Allow WooCommerce product updates.',
			],
			'allow_remote_update' => [
				'type'        => 'bool',
				'default'     => false,
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'Allow remote plugin update checks.',
			],
			'allow_external_telemetry' => [
				'type'        => 'bool',
				'default'     => false,
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'Allow outbound telemetry (must remain false by default).',
			],
			'require_human_approval' => [
				'type'        => 'bool',
				'default'     => true,
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'Require explicit human approval before any business mutation.',
			],
			'allow_background_content_generation' => [
				'type'        => 'bool',
				'default'     => false,
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'Allow background cron workers to run content generation.',
			],
			'allow_external_ai_requests' => [
				'type'        => 'bool',
				'default'     => false,
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'Allow outbound requests to allowlisted AI provider APIs.',
			],
			'emergency_stop' => [
				'type'        => 'bool',
				'default'     => false,
				'permission'  => Capabilities::MANAGE,
				'description' => 'Immediately halt all new jobs and business mutations.',
			],
			'log_retention_days' => [
				'type'        => 'int',
				'default'     => 30,
				'min'         => 1,
				'max'         => 365,
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'Number of days to retain audit logs.',
			],
			'ui_language' => [
				'type'        => 'enum',
				'values'      => ['vi', 'en', 'zh'],
				'default'     => 'vi',
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'Admin interface language.',
			],
			'active_ai_provider' => [
				'type'        => 'enum',
				'values'      => ['none', 'openai', 'gemini', 'claude', 'deepseek', 'grok', 'custom'],
				'default'     => 'none',
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'Configured primary AI provider.',
			],
			'allowed_post_types' => [
				'type'        => 'array',
				'default'     => ['post'],
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'WordPress post types permitted for workflow targeting.',
			],
			'primary_credential_ref' => [
				'type'        => 'secret_ref',
				'default'     => '',
				'permission'  => Capabilities::MANAGE_CREDENTIALS,
				'description' => 'Reference key in HTD Credential Vault.',
			],
			'delete_data_on_uninstall' => [
				'type'        => 'bool',
				'default'     => false,
				'permission'  => Capabilities::MANAGE_SETTINGS,
				'description' => 'Delete all plugin tables and options on uninstall.',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$defaults = [];
		foreach (self::schema() as $key => $def) {
			$defaults[$key] = $def['default'];
		}
		return $defaults;
	}

	public function ensureDefaults(): void {
		if (!function_exists('get_option') || !function_exists('add_option')) {
			return;
		}
		$existing = get_option(self::OPTION_NAME, null);
		if (!is_array($existing)) {
			add_option(self::OPTION_NAME, self::defaults(), '', false);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = function_exists('get_option') ? get_option(self::OPTION_NAME, []) : [];
		if (!is_array($stored)) {
			$stored = [];
		}
		return array_merge(self::defaults(), $stored);
	}

	public function get(string $key, mixed $fallback = null): mixed {
		$all = $this->all();
		return $all[$key] ?? $fallback;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function sanitize(array $input, ?array $base = null): array {
		$current = $base ?? $this->all();
		$schema = self::schema();

		foreach ($schema as $key => $def) {
			if (!array_key_exists($key, $input)) {
				continue;
			}
			$val = $input[$key];
			switch ($def['type']) {
				case 'bool':
					$current[$key] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
					break;
				case 'int':
					$intVal = (int) $val;
					$min = $def['min'] ?? PHP_INT_MIN;
					$max = $def['max'] ?? PHP_INT_MAX;
					$current[$key] = max($min, min($max, $intVal));
					break;
				case 'enum':
					$strVal = is_string($val) ? trim($val) : '';
					$current[$key] = in_array($strVal, $def['values'], true) ? $strVal : $def['default'];
					break;
				case 'array':
					$arr = is_array($val) ? $val : [];
					$current[$key] = array_values(array_unique(array_filter(array_map(
						static fn($item): string => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $item) ?? '',
						$arr
					))));
					break;
				case 'secret_ref':
				case 'string':
					$current[$key] = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', (string) $val) ?? '';
					break;
			}
		}
		return $current;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function update(array $input): bool {
		$sanitized = $this->sanitize($input);
		if (function_exists('update_option')) {
			return update_option(self::OPTION_NAME, $sanitized, false) || get_option(self::OPTION_NAME) === $sanitized;
		}
		return true;
	}
}
