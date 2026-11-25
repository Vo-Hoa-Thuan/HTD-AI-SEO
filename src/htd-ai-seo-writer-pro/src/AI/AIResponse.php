<?php
declare(strict_types=1);

namespace HTD\AISEO\AI;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Normalized AI provider response.
 */
final class AIResponse {
	/**
	 * @param array<string, mixed>|null $parsedJson
	 */
	public function __construct(
		public readonly string $providerId,
		public readonly string $model,
		public readonly string $rawText,
		public readonly ?array $parsedJson,
		public readonly UsageMetadata $usage,
		public readonly ?string $finishReason = null,
		public readonly bool $fromCache = false
	) {}
}
