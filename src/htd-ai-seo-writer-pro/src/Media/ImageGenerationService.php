<?php
declare(strict_types=1);

namespace HTD\AISEO\Media;

use HTD\AISEO\AI\AIError;
use HTD\AISEO\AI\AIException;
use HTD\AISEO\AI\Capability;
use HTD\AISEO\AI\ProviderRegistry;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Security\ExternalRequestGuard;
use HTD\AISEO\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Official AI image generation service (routed through ExternalRequestGuard + ImageValidator).
 * Supports capability-checked providers (e.g. OpenAI /v1/images/generations).
 */
final class ImageGenerationService {
	public function __construct(
		private readonly SettingsRegistry $settings,
		private readonly ProviderRegistry $providers,
		private readonly CredentialVault $vault,
		private readonly ExternalRequestGuard $httpGuard,
		private readonly ImageValidator $validator,
		private readonly Logger $logger
	) {}

	/**
	 * Build official OpenAI image generation HTTP payload.
	 *
	 * @return array<string, mixed>
	 */
	public function buildOpenAiImagePayload(string $prompt, string $apiKey, string $model = 'gpt-image-1', string $size = '1024x1024'): array {
		if (trim($prompt) === '') {
			throw new \InvalidArgumentException('Image prompt cannot be empty.');
		}
		return [
			'url'     => 'https://api.openai.com/v1/images/generations',
			'method'  => 'POST',
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $apiKey,
			],
			'body'    => (string) json_encode([
				'model'           => $model,
				'prompt'          => $prompt,
				'n'               => 1,
				'size'            => $size,
				'response_format' => 'b64_json',
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	/**
	 * Parse and security-validate base64 image response.
	 *
	 * @return array{mime: string, bytes: string}
	 */
	public function parseAndValidateImageResponse(string $body, string $filename = 'htd-generated.png'): array {
		$data = json_decode($body, true);
		$b64 = (string) ($data['data'][0]['b64_json'] ?? '');
		if ($b64 === '') {
			throw new AIException(AIError::INVALID_RESPONSE, 'Missing b64_json in image generation response.', 422);
		}
		$mime = $this->validator->validateBase64($b64, $filename);
		$bytes = (string) base64_decode($b64, true);
		return ['mime' => $mime, 'bytes' => $bytes];
	}

	/**
	 * Execute guarded AI image generation (requires allow_external_ai_requests=true & non-emergency).
	 *
	 * @return array{mime: string, bytes: string}
	 */
	public function generate(string $providerId, string $prompt, string $filename = 'htd-generated.png'): array {
		if ((bool) $this->settings->get('emergency_stop', false)) {
			throw new AIException(AIError::REQUEST_BLOCKED, 'EMERGENCY_STOP is active.', 423);
		}
		$provider = $this->providers->get($providerId);
		if (!$provider->supports(Capability::IMAGE_GENERATION)) {
			throw new AIException(AIError::UNSUPPORTED_CAPABILITY, "Provider '{$providerId}' does not support IMAGE_GENERATION.", 400);
		}
		$apiKey = $this->vault->getSecret($providerId . '_api_key') ?? '';
		if ($apiKey === '') {
			throw new AIException(AIError::INVALID_CREDENTIAL, 'API key missing for image generation.', 401);
		}

		$http = $this->buildOpenAiImagePayload($prompt, $apiKey);
		$res = $this->httpGuard->request($http['url'], [
			'method'  => $http['method'],
			'headers' => $http['headers'],
			'body'    => $http['body'],
			'timeout' => 60,
		]);
		if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
			throw new AIException(AIError::PROVIDER_UNAVAILABLE, "Image API returned HTTP {$res['status']}.", (int) $res['status']);
		}
		$parsed = $this->parseAndValidateImageResponse((string) $res['body'], $filename);
		$this->logger->log('media', 'ai_image_generated', 'INFO', 'OK', ['provider' => $providerId, 'mime' => $parsed['mime']]);
		return $parsed;
	}
}
