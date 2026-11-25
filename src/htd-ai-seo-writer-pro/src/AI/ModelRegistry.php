<?php
declare(strict_types=1);

namespace HTD\AISEO\AI;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Editable model & pricing registry with source attribution (never claims hardcoded permanent pricing).
 */
final class ModelRegistry {
	public const OPTION_NAME = 'htd_ai_seo_model_pricing';

	/**
	 * Default reference pricing (USD per 1M tokens) — editable by admin.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaultPricing(): array {
		return [
			'openai:gpt-4o-mini' => [
				'provider' => 'openai', 'input_per_1m' => 0.15, 'output_per_1m' => 0.60,
				'source' => 'Official OpenAI API pricing reference (editable)', 'updated_at' => '2026-11-25',
			],
			'openai:gpt-4o' => [
				'provider' => 'openai', 'input_per_1m' => 2.50, 'output_per_1m' => 10.00,
				'source' => 'Official OpenAI API pricing reference (editable)', 'updated_at' => '2026-11-25',
			],
			'gemini:gemini-2.5-flash' => [
				'provider' => 'gemini', 'input_per_1m' => 0.15, 'output_per_1m' => 0.60,
				'source' => 'Official Google Gemini API pricing reference (editable)', 'updated_at' => '2026-11-25',
			],
			'claude:claude-sonnet-4-20250514' => [
				'provider' => 'claude', 'input_per_1m' => 3.00, 'output_per_1m' => 15.00,
				'source' => 'Official Anthropic API pricing reference (editable)', 'updated_at' => '2026-11-25',
			],
			'deepseek:deepseek-chat' => [
				'provider' => 'deepseek', 'input_per_1m' => 0.27, 'output_per_1m' => 1.10,
				'source' => 'Official DeepSeek API pricing reference (editable)', 'updated_at' => '2026-11-25',
			],
			'grok:grok-3-mini' => [
				'provider' => 'grok', 'input_per_1m' => 0.30, 'output_per_1m' => 0.50,
				'source' => 'Official xAI API pricing reference (editable)', 'updated_at' => '2026-11-25',
			],
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function allPricing(): array {
		$custom = function_exists('get_option') ? get_option(self::OPTION_NAME, []) : [];
		if (!is_array($custom)) {
			$custom = [];
		}
		return array_merge(self::defaultPricing(), $custom);
	}

	public function estimateCostUsd(string $providerId, string $model, int $inputTokens, int $outputTokens): float {
		$key = $providerId . ':' . $model;
		$pricing = $this->allPricing()[$key] ?? null;
		if (!is_array($pricing)) {
			return 0.0;
		}
		$inCost  = ($inputTokens / 1_000_000) * (float) ($pricing['input_per_1m'] ?? 0.0);
		$outCost = ($outputTokens / 1_000_000) * (float) ($pricing['output_per_1m'] ?? 0.0);
		return round($inCost + $outCost, 6);
	}
}
