<?php
declare(strict_types=1);

namespace HTD\AISEO\Support;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * HTD capability definitions and authorization helpers.
 */
final class Capabilities {
	public const MANAGE             = 'htd_ai_seo_manage';
	public const VIEW_LOGS          = 'htd_ai_seo_view_logs';
	public const MANAGE_SETTINGS    = 'htd_ai_seo_manage_settings';
	public const MANAGE_CREDENTIALS = 'htd_ai_seo_manage_credentials';
	public const REVIEW             = 'htd_ai_seo_review';
	public const APPROVE            = 'htd_ai_seo_approve';
	public const PUBLISH            = 'htd_ai_seo_publish';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return [
			self::MANAGE,
			self::VIEW_LOGS,
			self::MANAGE_SETTINGS,
			self::MANAGE_CREDENTIALS,
			self::REVIEW,
			self::APPROVE,
			self::PUBLISH,
		];
	}

	public static function grantToAdministrator(): void {
		if (!function_exists('get_role')) {
			return;
		}
		$role = get_role('administrator');
		if ($role === null) {
			return;
		}
		foreach (self::all() as $cap) {
			$role->add_cap($cap);
		}
	}

	public static function currentUserCan(string $capability): bool {
		if (!function_exists('current_user_can')) {
			return false;
		}
		return current_user_can($capability) || current_user_can('manage_options');
	}
}
