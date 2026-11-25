<?php
declare(strict_types=1);

namespace HTD\AISEO\AI;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Normalized AI error codes and exception class.
 */
final class AIError {
	public const INVALID_CREDENTIAL     = 'INVALID_CREDENTIAL';
	public const INVALID_MODEL          = 'INVALID_MODEL';
	public const RATE_LIMITED           = 'RATE_LIMITED';
	public const QUOTA_EXCEEDED         = 'QUOTA_EXCEEDED';
	public const BILLING_REQUIRED       = 'BILLING_REQUIRED';
	public const TIMEOUT                = 'TIMEOUT';
	public const NETWORK_ERROR          = 'NETWORK_ERROR';
	public const INVALID_RESPONSE       = 'INVALID_RESPONSE';
	public const CONTENT_FILTERED       = 'CONTENT_FILTERED';
	public const UNSUPPORTED_CAPABILITY = 'UNSUPPORTED_CAPABILITY';
	public const PROVIDER_UNAVAILABLE   = 'PROVIDER_UNAVAILABLE';
	public const BUDGET_EXCEEDED        = 'BUDGET_EXCEEDED';
	public const REQUEST_BLOCKED        = 'REQUEST_BLOCKED';

	public static function isNonRetryable(string $code): bool {
		return in_array($code, [
			self::INVALID_CREDENTIAL,
			self::INVALID_MODEL,
			self::BILLING_REQUIRED,
			self::UNSUPPORTED_CAPABILITY,
			self::BUDGET_EXCEEDED,
			self::REQUEST_BLOCKED,
			self::CONTENT_FILTERED,
		], true);
	}
}
