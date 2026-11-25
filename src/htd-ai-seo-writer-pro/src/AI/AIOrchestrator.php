<?php
declare(strict_types=1);

namespace HTD\AISEO\AI;

use HTD\AISEO\Brand\BrandProfile;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Prompt\PromptRegistry;
use HTD\AISEO\Prompt\PromptRenderer;
use HTD\AISEO\Settings\SettingsRegistry;
use HTD\AISEO\Support\Capabilities;
use HTD\AISEO\Token\TokenBudgetManager;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Coordinates permission, safety, prompt rendering, budget, deduplication, retry, and validation.
 */
final class AIOrchestrator {
	/** @var array<string, bool> */
	private array $inflightFingerprints = [];

	public function __construct(
		private readonly SettingsRegistry $settings,
		private readonly ProviderRegistry $providers,
		private readonly ProviderConfiguration $providerConfigs,
		private readonly CredentialVault $vault,
		private readonly PromptRegistry $prompts,
		private readonly PromptRenderer $renderer,
		private readonly BrandProfile $brand,
		private readonly TokenBudgetManager $tokens,
		private readonly OutputValidator $validator,
		private readonly Logger $logger
	) {}

	/**
	 * @param array<string, mixed> $variables
	 * @return array{response: AIResponse, validated: array<string, mixed>}
	 */
	public function executeTask(
		string $providerId,
		string $templateId,
		array $variables,
		bool $skipCapabilityCheck = false,
		bool $useCache = true
	): array {
		if (!$skipCapabilityCheck && function_exists('current_user_can') && !Capabilities::currentUserCan(Capabilities::MANAGE)) {
			throw new AIException(AIError::REQUEST_BLOCKED, 'Insufficient permissions for AI execution.', 403);
		}
		if ((bool) $this->settings->get('emergency_stop', false)) {
			throw new AIException(AIError::REQUEST_BLOCKED, 'EMERGENCY_STOP is active.', 423);
		}
		if (!(bool) $this->settings->get('allow_external_ai_requests', false)) {
			throw new AIException(AIError::REQUEST_BLOCKED, 'ALLOW_EXTERNAL_AI_REQUESTS is false.', 403);
		}

		$tpl = $this->prompts->get($templateId);
		if ($tpl->status !== 'active') {
			throw new AIException(AIError::REQUEST_BLOCKED, "Prompt '{$templateId}' is disabled.", 400);
		}

		$messages = $this->renderer->render($tpl, $variables, $this->brand->toPromptContext());
		$cfg = $this->providerConfigs->get($providerId);
		if (empty($cfg['enabled'])) {
			throw new AIException(AIError::PROVIDER_UNAVAILABLE, "Provider '{$providerId}' is disabled.", 400);
		}

		$model = (string) ($cfg['model'] ?? '');
		$req = new AIRequest(
			providerId: $providerId,
			model: $model,
			messages: $messages,
			taskType: $tpl->taskType,
			maxOutputTokens: min($tpl->tokenBudget, (int) ($cfg['max_tokens'] ?? 2048)),
			temperature: isset($cfg['temperature']) ? (float) $cfg['temperature'] : null,
			timeoutSeconds: (int) ($cfg['timeout'] ?? 45),
			outputSchema: $tpl->outputSchema,
			correlationId: $this->logger->correlationId()
		);

		$fingerprint = $req->fingerprint($tpl->version);
		$scope = (function_exists('get_current_blog_id') ? get_current_blog_id() : 1) . ':' . (function_exists('get_current_user_id') ? get_current_user_id() : 0);

		if ($useCache) {
			$cached = $this->tokens->getCached($fingerprint, $scope);
			if ($cached !== null) {
				$validated = $this->validator->validate($cached, $tpl->outputSchema);
				$this->tokens->recordUsage($providerId, $model, $tpl->taskType, 0, 0, 0.0, true, true);
				return ['response' => $cached, 'validated' => $validated];
			}
		}

		if (isset($this->inflightFingerprints[$fingerprint])) {
			throw new AIException(AIError::REQUEST_BLOCKED, 'Duplicate in-flight AI request prevented.', 409);
		}

		$this->tokens->assertBudgetAvailable($providerId);
		$apiKey = $this->vault->getSecret((string) ($cfg['cred_ref'] ?? ($providerId . '_api_key'))) ?? '';
		$provider = $this->providers->get($providerId);

		$this->inflightFingerprints[$fingerprint] = true;
		$maxRetries = max(0, min(3, (int) ($cfg['retries'] ?? 1)));

		try {
			$attempt = 0;
			while (true) {
				try {
					$response = $provider->send($req, $apiKey);
					$validated = $this->validator->validate($response, $tpl->outputSchema);
					$this->tokens->recordUsage(
						$providerId,
						$model,
						$tpl->taskType,
						$response->usage->inputTokens,
						$response->usage->outputTokens,
						$response->usage->estimatedCostUsd,
						true,
						false
					);
					if ($useCache) {
						$this->tokens->putCache($fingerprint, $scope, $response);
					}
					$this->logger->log('ai_orchestrator', 'task_completed', 'INFO', 'OK', [
						'provider'    => $providerId,
						'model'       => $model,
						'template_id' => $templateId,
						'tokens'      => $response->usage->totalTokens,
					]);
					return ['response' => $response, 'validated' => $validated];
				} catch (AIException $e) {
					if ($attempt >= $maxRetries || AIError::isNonRetryable($e->errorCode)) {
						$this->tokens->recordUsage($providerId, $model, $tpl->taskType, 0, 0, 0.0, false, false);
						$this->logger->log('ai_orchestrator', 'task_failed', 'ERROR', $e->errorCode, [
							'provider'    => $providerId,
							'template_id' => $templateId,
							'error'       => $e->getMessage(),
						]);
						throw $e;
					}
					$attempt++;
				}
			}
		} finally {
			unset($this->inflightFingerprints[$fingerprint]);
		}
	}
}
