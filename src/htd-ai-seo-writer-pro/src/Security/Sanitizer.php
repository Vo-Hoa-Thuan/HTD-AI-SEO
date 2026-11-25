<?php
declare(strict_types=1);

namespace HTD\AISEO\Security;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * HTML and input sanitization helpers, including AI-generated HTML hardening (SEC_004).
 */
final class Sanitizer {
	public static function sanitizeAiHtml(string $html): string {
		$clean = function_exists('wp_kses_post') ? wp_kses_post($html) : strip_tags($html, '<p><h1><h2><h3><h4><ul><ol><li><strong><em><a><img><table><thead><tbody><tr><th><td><blockquote><code><pre><br>');
		// Defense-in-depth: strip any residual script/iframe/event handlers/javascript: URIs.
		$clean = preg_replace('/<\s*(script|iframe|object|embed)\b[^>]*>.*?<\/\1>/is', '', $clean) ?? $clean;
		$clean = preg_replace('/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? $clean;
		$clean = preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1=$2#$2', $clean) ?? $clean;
		return trim($clean);
	}

	public static function sanitizeIdentifier(string $value): string {
		return preg_replace('/[^a-zA-Z0-9_\-\.]/', '', trim($value)) ?? '';
	}
}
