<?php
declare(strict_types=1);

namespace HTD\AISEO\Support;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Runtime environment and compatibility detector.
 */
final class Environment {
	public static function isPhpCompatible(): bool {
		return version_compare(PHP_VERSION, '8.1.0', '>=');
	}

	public static function hasOpenSsl(): bool {
		return function_exists('openssl_encrypt') && function_exists('openssl_decrypt');
	}

	public static function isWooCommerceActive(): bool {
		return class_exists('WooCommerce');
	}

	public static function isRankMathActive(): bool {
		return defined('RANK_MATH_VERSION') || class_exists('RankMath');
	}

	public static function isLiteSpeed(): bool {
		$server = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
		return stripos($server, 'litespeed') !== false || PHP_SAPI === 'litespeed';
	}

	public static function wpVersion(): string {
		global $wp_version;
		return isset($wp_version) ? (string) $wp_version : 'unknown';
	}
}
