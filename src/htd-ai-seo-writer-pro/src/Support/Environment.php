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

	public static function hasMbstring(): bool {
		return function_exists('mb_substr') && function_exists('mb_strlen');
	}

	public static function hasJson(): bool {
		return function_exists('json_encode') && function_exists('json_decode');
	}

	public static function hasPcre(): bool {
		return function_exists('preg_match');
	}

	/**
	 * @return string[]
	 */
	public static function missingRequiredExtensions(): array {
		$missing = [];
		if (!self::hasOpenSsl()) {
			$missing[] = 'openssl';
		}
		if (!self::hasJson()) {
			$missing[] = 'json';
		}
		if (!self::hasMbstring()) {
			$missing[] = 'mbstring';
		}
		if (!self::hasPcre()) {
			$missing[] = 'pcre';
		}
		return $missing;
	}

	public static function safeSubstr(string $text, int $start, ?int $length = null): string {
		if (function_exists('mb_substr')) {
			return $length === null ? mb_substr($text, $start, null, 'UTF-8') : mb_substr($text, $start, $length, 'UTF-8');
		}
		$chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
		if (is_array($chars)) {
			return implode('', array_slice($chars, $start, $length));
		}
		return $length === null ? substr($text, $start) : substr($text, $start, $length);
	}

	public static function safeStrlen(string $text): int {
		if (function_exists('mb_strlen')) {
			return mb_strlen($text, 'UTF-8');
		}
		$chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
		return is_array($chars) ? count($chars) : strlen($text);
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
