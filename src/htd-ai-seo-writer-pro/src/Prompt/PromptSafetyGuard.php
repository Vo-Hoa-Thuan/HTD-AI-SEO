<?php
declare(strict_types=1);

namespace HTD\AISEO\Prompt;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Guards prompt templates and variables against code injection and prompt injection.
 */
final class PromptSafetyGuard {
	public static function assertSafeTemplate(PromptTemplate $tpl): void {
		$combined = $tpl->systemInstructions . "\n" . $tpl->userTemplate;
		if (preg_match('/<\?php|<\?=|<script\b/i', $combined)) {
			throw new \InvalidArgumentException('Prompt templates cannot contain PHP or JavaScript code.');
		}
		if (preg_match('/(htd_ai_seo_credentials|CredentialVault|getSecret|api_key_raw)/i', $combined)) {
			throw new \InvalidArgumentException('Prompt templates cannot reference credential internals.');
		}
	}

	/**
	 * Isolate untrusted user/product/site data from system instructions.
	 */
	public static function wrapUntrustedVariable(string $key, mixed $value): string {
		$str = is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		// Neutralize closing tag attempts inside untrusted input
		$safe = str_ireplace('</untrusted_data>', '[filtered]', $str);
		return "<untrusted_data field=\"{$key}\">{$safe}</untrusted_data>";
	}
}
