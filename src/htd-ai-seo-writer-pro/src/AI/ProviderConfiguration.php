<?php
declare(strict_types=1);

namespace HTD\AISEO\AI;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Per-provider configuration store (non-secret settings; API keys stay in CredentialVault).
 */
final class ProviderConfiguration {
	public const OPTION_NAME = 'htd_ai_seo_provider_configs';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		return [
			'openai'   => ['enabled' => false, 'model' => 'gpt-4o-mini', 'temperature' => 0.3, 'max_tokens' => 2048, 'timeout' => 45, 'retries' => 2, 'daily_budget_usd' => 5.0, 'monthly_budget_usd' => 50.0, 'cred_ref' => 'openai_api_key'],
			'gemini'   => ['enabled' => false, 'model' => 'gemini-2.5-flash', 'temperature' => 0.3, 'max_tokens' => 2048, 'timeout' => 45, 'retries' => 2, 'daily_budget_usd' => 5.0, 'monthly_budget_usd' => 50.0, 'cred_ref' => 'gemini_api_key'],
			'claude'   => ['enabled' => false, 'model' => 'claude-sonnet-4-20250514', 'temperature' => 0.3, 'max_tokens' => 2048, 'timeout' => 45, 'retries' => 2, 'daily_budget_usd' => 5.0, 'monthly_budget_usd' => 50.0, 'cred_ref' => 'claude_api_key'],
			'deepseek' => ['enabled' => false, 'model' => 'deepseek-chat', 'temperature' => 0.3, 'max_tokens' => 2048, 'timeout' => 45, 'retries' => 2, 'daily_budget_usd' => 2.0, 'monthly_budget_usd' => 20.0, 'cred_ref' => 'deepseek_api_key'],
			'grok'     => ['enabled' => false, 'model' => 'grok-3-mini', 'temperature' => 0.3, 'max_tokens' => 2048, 'timeout' => 45, 'retries' => 2, 'daily_budget_usd' => 2.0, 'monthly_budget_usd' => 20.0, 'cred_ref' => 'grok_api_key'],
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$stored = function_exists('get_option') ? get_option(self::OPTION_NAME, []) : [];
		if (!is_array($stored)) {
			$stored = [];
		}
		$merged = [];
		foreach (self::defaults() as $id => $def) {
			$merged[$id] = array_merge($def, is_array($stored[$id] ?? null) ? $stored[$id] : []);
		}
		return $merged;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(string $providerId): array {
		return $this->all()[$providerId] ?? [];
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public function save(string $providerId, array $config): void {
		$all = $this->all();
		if (!isset($all[$providerId])) {
			throw new \InvalidArgumentException("Unknown provider: {$providerId}");
		}
		$all[$providerId] = array_merge($all[$providerId], [
			'enabled'            => !empty($config['enabled']),
			'model'              => preg_replace('/[^a-zA-Z0-9\.\-_]/', '', (string) ($config['model'] ?? $all[$providerId]['model'])) ?? '',
			'temperature'        => max(0.0, min(2.0, (float) ($config['temperature'] ?? $all[$providerId]['temperature']))),
			'max_tokens'         => max(64, min(32768, (int) ($config['max_tokens'] ?? $all[$providerId]['max_tokens']))),
			'timeout'            => max(5, min(60, (int) ($config['timeout'] ?? $all[$providerId]['timeout']))),
			'retries'            => max(0, min(5, (int) ($config['retries'] ?? $all[$providerId]['retries']))),
			'daily_budget_usd'   => max(0.0, (float) ($config['daily_budget_usd'] ?? $all[$providerId]['daily_budget_usd'])),
			'monthly_budget_usd' => max(0.0, (float) ($config['monthly_budget_usd'] ?? $all[$providerId]['monthly_budget_usd'])),
		]);
		if (function_exists('update_option')) {
			update_option(self::OPTION_NAME, $all, false);
		}
	}
}
