<?php
declare(strict_types=1);

namespace HTD\AISEO\AI;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Distinguishes estimated vs provider-reported token usage and cost.
 */
final class UsageMetadata {
	public function __construct(
		public readonly int $inputTokens,
		public readonly int $outputTokens,
		public readonly int $totalTokens,
		public readonly string $source = 'PROVIDER_REPORTED_TOKENS',
		public readonly float $estimatedCostUsd = 0.0,
		public readonly string $costSource = 'ESTIMATED_COST'
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'input_tokens'       => $this->inputTokens,
			'output_tokens'      => $this->outputTokens,
			'total_tokens'       => $this->totalTokens,
			'token_source'       => $this->source,
			'estimated_cost_usd' => $this->estimatedCostUsd,
			'cost_source'        => $this->costSource,
		];
	}
}
