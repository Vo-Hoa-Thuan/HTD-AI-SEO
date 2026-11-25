<?php
declare(strict_types=1);

namespace HTD\AISEO\AI;

use HTD\AISEO\Contracts\AiProviderInterface;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Registry of official AI providers.
 */
final class ProviderRegistry {
	/** @var array<string, AiProviderInterface> */
	private array $providers = [];

	public function register(AiProviderInterface $provider): void {
		$this->providers[$provider->getProviderId()] = $provider;
	}

	public function get(string $providerId): AiProviderInterface {
		if (!isset($this->providers[$providerId])) {
			throw new AIException(AIError::PROVIDER_UNAVAILABLE, "Provider '{$providerId}' is not registered.", 404);
		}
		return $this->providers[$providerId];
	}

	public function has(string $providerId): bool {
		return isset($this->providers[$providerId]);
	}

	/**
	 * @return array<string, AiProviderInterface>
	 */
	public function all(): array {
		return $this->providers;
	}
}
