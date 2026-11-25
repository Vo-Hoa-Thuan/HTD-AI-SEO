<?php
declare(strict_types=1);

namespace HTD\AISEO\Token;

use HTD\AISEO\AI\AIError;
use HTD\AISEO\AI\AIException;
use HTD\AISEO\AI\AIResponse;
use HTD\AISEO\AI\ProviderConfiguration;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Token estimation, context trimming, budget enforcement, usage accounting, and safe response caching.
 */
final class TokenBudgetManager {
	public const USAGE_OPTION        = 'htd_ai_seo_ai_usage';
	public const CACHE_OPTION        = 'htd_ai_seo_ai_cache';
	public const RESERVATIONS_OPTION = 'htd_ai_seo_budget_reservations';
	private const UNKNOWN_IN_PER_1M  = 5.0;
	private const UNKNOWN_OUT_PER_1M = 15.0;

	public function __construct(
		private readonly ProviderConfiguration $providerConfigs,
		private readonly ?\HTD\AISEO\AI\ModelRegistry $models = null
	) {}

	public function estimateRequestCostUsd(string $providerId, string $model, int $inputTokens, int $maxOutputTokens): float {
		$cfg = $this->providerConfigs->get($providerId);
		if ($providerId === 'custom') {
			$inRate = (float) ($cfg['input_per_1m'] ?? 0.0);
			$outRate = (float) ($cfg['output_per_1m'] ?? 0.0);
			if ($inRate > 0.0 || $outRate > 0.0) {
				return round((($inputTokens / 1_000_000) * $inRate) + (($maxOutputTokens / 1_000_000) * $outRate), 6);
			}
		}
		if ($this->models !== null) {
			$known = $this->models->estimateCostUsd($providerId, $model, $inputTokens, $maxOutputTokens);
			if ($known > 0.0) {
				return $known;
			}
		}
		// Unknown pricing must NOT be treated as free; apply conservative fallback estimate.
		return round((($inputTokens / 1_000_000) * self::UNKNOWN_IN_PER_1M) + (($maxOutputTokens / 1_000_000) * self::UNKNOWN_OUT_PER_1M), 6);
	}

	/**
	 * Atomically reserve estimated budget before sending an outbound AI request.
	 */
	public function reserveBudget(string $providerId, float $estimatedUsd, int $ttlSeconds = 180): string {
		$this->pruneExpiredReservations();
		$this->assertBudgetAvailable($providerId, $estimatedUsd);

		$reservations = function_exists('get_option') ? get_option(self::RESERVATIONS_OPTION, []) : [];
		if (!is_array($reservations)) {
			$reservations = [];
		}
		$resId = 'res_' . bin2hex(random_bytes(8));
		$reservations[$resId] = [
			'provider_id' => $providerId,
			'amount_usd'  => round(max(0.0, $estimatedUsd), 6),
			'expires_at'  => time() + max(30, $ttlSeconds),
		];
		if (function_exists('update_option')) {
			update_option(self::RESERVATIONS_OPTION, $reservations, false);
		}
		return $resId;
	}

	public function releaseReservation(string $reservationId): void {
		$reservations = function_exists('get_option') ? get_option(self::RESERVATIONS_OPTION, []) : [];
		if (!is_array($reservations) || !isset($reservations[$reservationId])) {
			return;
		}
		unset($reservations[$reservationId]);
		if (function_exists('update_option')) {
			update_option(self::RESERVATIONS_OPTION, $reservations, false);
		}
	}

	public function reconcileReservation(
		string $reservationId,
		string $providerId,
		string $model,
		string $taskType,
		int $inTok,
		int $outTok,
		float $actualCostUsd,
		bool $success,
		bool $cacheHit = false
	): void {
		$this->releaseReservation($reservationId);
		$effectiveCost = $actualCostUsd;
		if ($effectiveCost <= 0.0 && ($inTok > 0 || $outTok > 0)) {
			$effectiveCost = $this->estimateRequestCostUsd($providerId, $model, $inTok, $outTok);
		}
		$this->recordUsage($providerId, $model, $taskType, $inTok, $outTok, $effectiveCost, $success, $cacheHit);
	}

	private function activeReservedUsd(string $providerId): float {
		$this->pruneExpiredReservations();
		$reservations = function_exists('get_option') ? get_option(self::RESERVATIONS_OPTION, []) : [];
		if (!is_array($reservations)) {
			return 0.0;
		}
		$sum = 0.0;
		foreach ($reservations as $r) {
			if (is_array($r) && ($r['provider_id'] ?? '') === $providerId) {
				$sum += (float) ($r['amount_usd'] ?? 0.0);
			}
		}
		return round($sum, 6);
	}

	private function pruneExpiredReservations(): void {
		$reservations = function_exists('get_option') ? get_option(self::RESERVATIONS_OPTION, []) : [];
		if (!is_array($reservations) || $reservations === []) {
			return;
		}
		$now = time();
		$changed = false;
		foreach ($reservations as $id => $r) {
			if (!is_array($r) || (int) ($r['expires_at'] ?? 0) < $now) {
				unset($reservations[$id]);
				$changed = true;
			}
		}
		if ($changed && function_exists('update_option')) {
			update_option(self::RESERVATIONS_OPTION, $reservations, false);
		}
	}

	public static function estimateTokens(string $text): int {
		$len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
		return max(1, (int) ceil($len / 3.5));
	}

	/**
	 * Trim and deduplicate context lines to stay within input token budget.
	 *
	 * @param string[] $chunks
	 * @return string[]
	 */
	public function trimContext(array $chunks, int $maxInputTokens): array {
		$seen = [];
		$out = [];
		$used = 0;
		foreach ($chunks as $chunk) {
			$norm = trim($chunk);
			if ($norm === '' || isset($seen[$norm])) {
				continue;
			}
			$seen[$norm] = true;
			$tok = self::estimateTokens($norm);
			if ($used + $tok > $maxInputTokens) {
				break;
			}
			$out[] = $norm;
			$used += $tok;
		}
		return $out;
	}

	public function assertBudgetAvailable(string $providerId, float $estimatedAdditionalUsd = 0.0): void {
		$cfg = $this->providerConfigs->get($providerId);
		$dailyCap = (float) ($cfg['daily_budget_usd'] ?? 0.0);
		$monthlyCap = (float) ($cfg['monthly_budget_usd'] ?? 0.0);

		$usage = $this->usageSummary();
		$today = gmdate('Y-m-d');
		$month = gmdate('Y-m');

		$reserved = $this->activeReservedUsd($providerId);
		$dailySpent = (float) ($usage['daily'][$today][$providerId]['cost_usd'] ?? 0.0) + $reserved;
		$monthlySpent = (float) ($usage['monthly'][$month][$providerId]['cost_usd'] ?? 0.0) + $reserved;

		if ($dailyCap > 0 && ($dailySpent + $estimatedAdditionalUsd) > $dailyCap) {
			throw new AIException(AIError::BUDGET_EXCEEDED, "Daily budget exceeded for {$providerId}.", 429);
		}
		if ($monthlyCap > 0 && ($monthlySpent + $estimatedAdditionalUsd) > $monthlyCap) {
			throw new AIException(AIError::BUDGET_EXCEEDED, "Monthly budget exceeded for {$providerId}.", 429);
		}
	}

	public function recordUsage(string $providerId, string $model, string $taskType, int $inTok, int $outTok, float $costUsd, bool $success, bool $cacheHit = false): void {
		$usage = $this->usageSummary();
		$today = gmdate('Y-m-d');
		$month = gmdate('Y-m');

		$usage['totals']['requests'] = ($usage['totals']['requests'] ?? 0) + 1;
		$usage['totals'][$success ? 'success' : 'failed'] = ($usage['totals'][$success ? 'success' : 'failed'] ?? 0) + 1;
		if ($cacheHit) {
			$usage['totals']['cache_hits'] = ($usage['totals']['cache_hits'] ?? 0) + 1;
		}
		$usage['totals']['input_tokens'] = ($usage['totals']['input_tokens'] ?? 0) + $inTok;
		$usage['totals']['output_tokens'] = ($usage['totals']['output_tokens'] ?? 0) + $outTok;
		$usage['totals']['cost_usd'] = round((float) ($usage['totals']['cost_usd'] ?? 0.0) + $costUsd, 6);

		foreach (['daily' => $today, 'monthly' => $month] as $bucket => $period) {
			if (!isset($usage[$bucket][$period][$providerId])) {
				$usage[$bucket][$period][$providerId] = ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0];
			}
			$usage[$bucket][$period][$providerId]['requests']++;
			$usage[$bucket][$period][$providerId]['input_tokens'] += $inTok;
			$usage[$bucket][$period][$providerId]['output_tokens'] += $outTok;
			$usage[$bucket][$period][$providerId]['cost_usd'] = round($usage[$bucket][$period][$providerId]['cost_usd'] + $costUsd, 6);
		}

		$usage['by_task'][$taskType] = ($usage['by_task'][$taskType] ?? 0) + 1;

		if (function_exists('update_option')) {
			update_option(self::USAGE_OPTION, $usage, false);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function usageSummary(): array {
		$raw = function_exists('get_option') ? get_option(self::USAGE_OPTION, []) : [];
		return is_array($raw) ? $raw : [];
	}

	public function getCached(string $fingerprint, string $scope): ?AIResponse {
		$cache = function_exists('get_option') ? get_option(self::CACHE_OPTION, []) : [];
		$key = hash('sha256', $scope . '|' . $fingerprint);
		$entry = is_array($cache[$key] ?? null) ? $cache[$key] : null;
		if ($entry === null || (int) ($entry['expires_at'] ?? 0) < time()) {
			return null;
		}
		return new AIResponse(
			providerId: (string) $entry['provider_id'],
			model: (string) $entry['model'],
			rawText: (string) $entry['raw_text'],
			parsedJson: is_array($entry['parsed_json']) ? $entry['parsed_json'] : null,
			usage: new \HTD\AISEO\AI\UsageMetadata(0, 0, 0, 'CACHE_HIT', 0.0, 'CACHE_HIT'),
			finishReason: 'cache_hit',
			fromCache: true
		);
	}

	public function putCache(string $fingerprint, string $scope, AIResponse $response, int $ttlSeconds = 3600): void {
		$cache = function_exists('get_option') ? get_option(self::CACHE_OPTION, []) : [];
		if (!is_array($cache)) {
			$cache = [];
		}
		$key = hash('sha256', $scope . '|' . $fingerprint);
		$cache[$key] = [
			'provider_id' => $response->providerId,
			'model'       => $response->model,
			'raw_text'    => $response->rawText,
			'parsed_json' => $response->parsedJson,
			'expires_at'  => time() + max(60, $ttlSeconds),
		];
		// Keep cache bounded to 50 entries
		if (count($cache) > 50) {
			$cache = array_slice($cache, -50, null, true);
		}
		if (function_exists('update_option')) {
			update_option(self::CACHE_OPTION, $cache, false);
		}
	}
}
