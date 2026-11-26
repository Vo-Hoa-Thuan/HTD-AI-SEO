<?php
declare(strict_types=1);

namespace HTD\AISEO\Queue;

use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Worker manager & job dispatcher enforcing Emergency Stop and lease ownership before execution.
 */
final class WorkerEngine {
	public const JOB_TYPES = [
		'KEYWORD_ANALYSIS',
		'KEYWORD_CLUSTER',
		'CONTENT_OUTLINE',
		'CONTENT_GENERATION',
		'CONTENT_REWRITE',
		'PRODUCT_CONTENT',
		'IMAGE_GENERATION',
		'CONTENT_QA',
		'INTERNAL_LINK_ANALYSIS',
		'PUBLISH_APPROVED_CONTENT',
		'HTD_SEO_AUDIT',
	];

	/** @var array<string, callable> */
	private array $handlers = [];

	public function __construct(
		private readonly QueueEngine $queue,
		private readonly SettingsRegistry $settings,
		private readonly Logger $logger
	) {}

	public function registerHandler(string $jobType, callable $handler): void {
		$this->handlers[$jobType] = $handler;
	}

	/**
	 * @return string[]
	 */
	public function registeredJobTypes(): array {
		return array_keys($this->handlers);
	}

	/**
	 * Process up to $maxJobs safely while respecting Emergency Stop and lease ownership.
	 *
	 * @return array{processed: int, completed: int, failed: int, stopped_reason: ?string}
	 */
	public function runBatch(string $workerId, int $maxJobs = 3, int $leaseSeconds = 120): array {
		$processed = 0;
		$completed = 0;
		$failed = 0;

		for ($i = 0; $i < max(1, $maxJobs); $i++) {
			if ((bool) $this->settings->get('emergency_stop', false)) {
				return ['processed' => $processed, 'completed' => $completed, 'failed' => $failed, 'stopped_reason' => 'EMERGENCY_STOP'];
			}

			$job = $this->queue->claimNext($workerId, $leaseSeconds);
			if ($job === null) {
				break;
			}
			$processed++;
			$jobId = (int) $job['id'];
			$jobType = (string) $job['job_type'];
			$payload = json_decode((string) ($job['payload_json'] ?? '{}'), true) ?: [];

			try {
				if ((bool) $this->settings->get('emergency_stop', false)) {
					throw new \RuntimeException('Halted mid-batch: EMERGENCY_STOP activated.');
				}
				if (!isset($this->handlers[$jobType])) {
					throw new \RuntimeException("No handler registered for job type: {$jobType}");
				}
				$this->queue->assertActiveLease($jobId, $workerId);

				$handlerResult = ($this->handlers[$jobType])($payload, $job);
				$resultArr = is_array($handlerResult) ? $handlerResult : ['ok' => true];
				$outcome = strtoupper((string) ($resultArr['status'] ?? 'COMPLETED'));
				if ($outcome === 'BLOCKED' || $outcome === 'BLOCKED_BY_CONFIGURATION' || $outcome === 'BLOCKED_BY_EMERGENCY_STOP') {
					$this->queue->recordResult($jobId, 'BLOCKED', $resultArr);
					throw new \RuntimeException((string) ($resultArr['reason'] ?? "Job #{$jobId} blocked by configuration."));
				}

				$this->queue->assertActiveLease($jobId, $workerId);
				if (!$this->queue->complete($jobId, $workerId)) {
					throw new \RuntimeException("Failed to complete job #{$jobId}; lease lost.");
				}
				$finalStatus = ($outcome === 'PARTIAL' || ($resultArr['audit_completeness'] ?? '') === 'PARTIAL') ? 'PARTIAL' : 'COMPLETED';
				$this->queue->recordResult($jobId, $finalStatus, $resultArr);
				$completed++;
			} catch (\Throwable $e) {
				$this->queue->fail($jobId, $workerId, $e->getMessage());
				if ($this->queue->getResult($jobId) === null) {
					$this->queue->recordResult($jobId, 'FAILED', ['error' => $e->getMessage()]);
				}
				$this->logger->log('worker', 'job_failed', 'ERROR', 'FAILED', [
					'job_id'   => $jobId,
					'job_type' => $jobType,
					'error'    => $e->getMessage(),
				]);
				$failed++;
			}
		}

		return ['processed' => $processed, 'completed' => $completed, 'failed' => $failed, 'stopped_reason' => null];
	}
}
