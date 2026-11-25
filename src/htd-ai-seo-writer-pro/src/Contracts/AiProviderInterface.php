<?php
declare(strict_types=1);

namespace HTD\AISEO\Contracts;

use HTD\AISEO\AI\AIRequest;
use HTD\AISEO\AI\AIResponse;

/**
 * Contract for official AI Provider adapters.
 */
interface AiProviderInterface {
	public function getProviderId(): string;

	/**
	 * @return string[]
	 */
	public function supportedCapabilities(): array;

	public function supports(string $capability): bool;

	public function send(AIRequest $request, string $apiKey): AIResponse;

	/**
	 * @param array<int, array{role: string, content: string}> $messages
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public function completeJson(array $messages, array $options = []): array;
}
