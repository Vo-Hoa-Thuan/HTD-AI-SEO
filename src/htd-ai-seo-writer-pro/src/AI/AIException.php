<?php
declare(strict_types=1);

namespace HTD\AISEO\AI;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Typed AI exception carrying normalized error code and HTTP/retry metadata.
 */
final class AIException extends \RuntimeException {
	public function __construct(
		public readonly string $errorCode,
		string $message,
		public readonly int $httpStatus = 0,
		public readonly ?int $retryAfterSeconds = null
	) {
		parent::__construct($message, $httpStatus);
	}
}
