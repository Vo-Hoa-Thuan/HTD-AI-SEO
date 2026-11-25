<?php
declare(strict_types=1);

namespace HTD\AISEO\Security;

use HTD\AISEO\Support\Capabilities;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Centralized Nonce + Capability verifier for Admin POST, AJAX, and REST requests.
 */
final class RequestVerifier {
	public static function verifyAdminAction(string $nonceAction, string $capability = Capabilities::MANAGE, string $nonceField = '_wpnonce'): void {
		if (!Capabilities::currentUserCan($capability)) {
			if (function_exists('wp_die')) {
				wp_die('Unauthorized: insufficient HTD permissions.', 403);
			}
			throw new \RuntimeException('Unauthorized: insufficient HTD permissions.', 403);
		}

		if (function_exists('check_admin_referer')) {
			check_admin_referer($nonceAction, $nonceField);
		}
	}

	public static function verifyAjax(string $nonceAction, string $capability = Capabilities::MANAGE, string $queryArg = 'nonce'): void {
		if (!Capabilities::currentUserCan($capability)) {
			if (function_exists('wp_send_json_error')) {
				wp_send_json_error(['message' => 'Forbidden'], 403);
			}
			throw new \RuntimeException('Forbidden', 403);
		}
		if (function_exists('check_ajax_referer')) {
			check_ajax_referer($nonceAction, $queryArg);
		}
	}
}
