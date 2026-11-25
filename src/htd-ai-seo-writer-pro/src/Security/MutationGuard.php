<?php
declare(strict_types=1);

namespace HTD\AISEO\Security;

use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Centralized guard enforced before any WordPress LIVE or business mutation.
 */
final class MutationGuard {
	public function __construct(
		private readonly SettingsRegistry $settings,
		private readonly ApprovalEngine $approvals,
		private readonly Logger $logger
	) {}

	/**
	 * Assert that a business mutation is permitted under current safety controls and approval state.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function assertCanMutate(
		string $actionType,
		string $targetType,
		int $targetId,
		array $payload,
		?int $approvalId = null
	): void {
		if ((bool) $this->settings->get('emergency_stop', false)) {
			$this->deny($actionType, 'EMERGENCY_STOP is active.');
		}

		if ((bool) $this->settings->get('wp_read_only_mode', true)) {
			$this->deny($actionType, 'WP_READ_ONLY_MODE is enabled.');
		}

		if ($actionType === 'autopublish' && !(bool) $this->settings->get('allow_autopublish', false)) {
			$this->deny($actionType, 'ALLOW_AUTOPUBLISH is disabled.');
		}

		if ($targetType === 'product' && !(bool) $this->settings->get('allow_product_update', false)) {
			$this->deny($actionType, 'ALLOW_PRODUCT_UPDATE is disabled.');
		}

		if ((bool) $this->settings->get('require_human_approval', true)) {
			if ($approvalId === null || $approvalId <= 0) {
				$this->deny($actionType, 'Human approval ID is required.');
			}
			$this->approvals->assertExecutable($approvalId, $actionType, $targetType, $targetId, $payload);
		}
	}

	private function deny(string $actionType, string $reason): never {
		$this->logger->log('mutation_guard', 'mutation_blocked', 'SECURITY', 'DENIED', [
			'action_type' => $actionType,
			'reason'      => $reason,
		]);
		throw new \RuntimeException("Mutation blocked: {$reason}", 403);
	}
}
