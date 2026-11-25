<?php
declare(strict_types=1);

namespace HTD\AISEO\AI;

use HTD\AISEO\Security\Sanitizer;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Validates and sanitizes structured AI JSON responses before any downstream use.
 */
final class OutputValidator {
	/**
	 * @param array<string, string> $schema Field => expected type (string|int|number|array|bool)
	 * @return array<string, mixed>
	 */
	public function validate(AIResponse $response, array $schema = [], int $maxStringLength = 50000): array {
		if (!is_array($response->parsedJson)) {
			throw new AIException(AIError::INVALID_RESPONSE, 'AI response is not valid JSON.', 422);
		}

		$data = $response->parsedJson;
		foreach ($schema as $field => $expectedType) {
			if (!array_key_exists($field, $data)) {
				throw new AIException(AIError::INVALID_RESPONSE, "Missing required JSON field: {$field}", 422);
			}
			$val = $data[$field];
			$validType = match ($expectedType) {
				'string' => is_string($val),
				'int'    => is_int($val),
				'number' => is_int($val) || is_float($val),
				'array'  => is_array($val),
				'bool'   => is_bool($val),
				default  => true,
			};
			if (!$validType) {
				throw new AIException(AIError::INVALID_RESPONSE, "Field '{$field}' expected type {$expectedType}.", 422);
			}
			if (is_string($val)) {
				if (strlen($val) > $maxStringLength) {
					throw new AIException(AIError::INVALID_RESPONSE, "Field '{$field}' exceeded max length.", 422);
				}
				if (preg_match('/<\?php|<\?=|\[vc_/i', $val)) {
					throw new AIException(AIError::CONTENT_FILTERED, "Unsafe executable content detected in '{$field}'.", 422);
				}
				if (str_contains($field, 'html')) {
					$data[$field] = Sanitizer::sanitizeAiHtml($val);
				} elseif (preg_match('/<script\b/i', $val)) {
					throw new AIException(AIError::CONTENT_FILTERED, "Unsafe script tag detected in '{$field}'.", 422);
				}
			}
		}

		return $data;
	}
}
