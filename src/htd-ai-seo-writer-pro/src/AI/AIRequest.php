<?php
declare(strict_types=1);

namespace HTD\AISEO\AI;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Immutable AI request payload.
 */
final class AIRequest {
	/**
	 * @param array<int, array{role: string, content: string}> $messages
	 * @param string[] $requiredCapabilities
	 * @param array<string, mixed>|null $outputSchema
	 */
	public function __construct(
		public readonly string $providerId,
		public readonly string $model,
		public readonly array $messages,
		public readonly string $taskType = 'general',
		public readonly int $maxOutputTokens = 2048,
		public readonly ?float $temperature = null,
		public readonly int $timeoutSeconds = 45,
		public readonly array $requiredCapabilities = [Capability::TEXT_GENERATION, Capability::JSON_OUTPUT],
		public readonly ?array $outputSchema = null,
		public readonly ?string $correlationId = null
	) {}

	public function fingerprint(string $promptVersion = '1.0'): string {
		return hash('sha256', (string) json_encode([
			'provider'       => $this->providerId,
			'model'          => $this->model,
			'messages'       => $this->messages,
			'task'           => $this->taskType,
			'max_tokens'     => $this->maxOutputTokens,
			'prompt_version' => $promptVersion,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
}
