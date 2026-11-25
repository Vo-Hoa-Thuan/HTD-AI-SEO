<?php
declare(strict_types=1);

namespace HTD\AISEO\Approval;

use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Payload-bound human approval state machine preventing unapproved, expired, or double executions.
 */
final class ApprovalEngine {
	public const STATUS_DRAFT          = 'DRAFT';
	public const STATUS_REVIEW_PENDING = 'REVIEW_PENDING';
	public const STATUS_APPROVED       = 'APPROVED';
	public const STATUS_REJECTED       = 'REJECTED';
	public const STATUS_EXPIRED        = 'EXPIRED';
	public const STATUS_EXECUTING      = 'EXECUTING';
	public const STATUS_EXECUTED       = 'EXECUTED';
	public const STATUS_FAILED         = 'FAILED';
	public const STATUS_CANCELLED      = 'CANCELLED';

	public function __construct(
		private readonly object $wpdb,
		private readonly Logger $logger
	) {}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function hashPayload(array $payload): string {
		$normalized = $payload;
		ksort($normalized);
		return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed>|null $previousSnapshot
	 */
	public function createRequest(
		string $actionType,
		string $targetType,
		int $targetId,
		array $payload,
		int $requestedBy = 0,
		int $ttlSeconds = 86400,
		?array $previousSnapshot = null,
		bool $submitImmediately = true
	): int {
		$table = Schema::table($this->wpdb, 'approvals');
		$now = gmdate('Y-m-d H:i:s');
		$expires = gmdate('Y-m-d H:i:s', time() + max(60, $ttlSeconds));
		$status = $submitImmediately ? self::STATUS_REVIEW_PENDING : self::STATUS_DRAFT;

		$this->wpdb->insert($table, [
			'action_type'            => $actionType,
			'target_type'            => $targetType,
			'target_id'              => $targetId,
			'payload_json'           => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'payload_hash'           => self::hashPayload($payload),
			'previous_snapshot_json' => $previousSnapshot !== null ? (string) json_encode($previousSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
			'status'                 => $status,
			'requested_by'           => $requestedBy,
			'requested_at_utc'       => $now,
			'expires_at_utc'         => $expires,
		]);
		$id = (int) $this->wpdb->insert_id;

		$this->logger->log('approval', 'request_created', 'AUDIT', 'OK', [
			'approval_id' => $id,
			'action_type' => $actionType,
			'target_type' => $targetType,
			'target_id'   => $targetId,
			'status'      => $status,
		]);
		return $id;
	}

	public function approve(int $approvalId, int $approvedBy, string $note = ''): bool {
		$row = $this->find($approvalId);
		if ($row === null) {
			throw new \RuntimeException("Approval #{$approvalId} not found.");
		}
		if (!in_array($row['status'], [self::STATUS_DRAFT, self::STATUS_REVIEW_PENDING], true)) {
			throw new \RuntimeException("Approval #{$approvalId} cannot be approved from status {$row['status']}.");
		}
		if (!empty($row['expires_at_utc']) && strtotime((string) $row['expires_at_utc']) < time()) {
			$this->markStatus($approvalId, self::STATUS_EXPIRED);
			throw new \RuntimeException("Approval #{$approvalId} has expired.");
		}

		$table = Schema::table($this->wpdb, 'approvals');
		$this->wpdb->update(
			$table,
			[
				'status'          => self::STATUS_APPROVED,
				'approved_by'     => $approvedBy,
				'approved_at_utc' => gmdate('Y-m-d H:i:s'),
				'decision_note'   => $note !== '' ? $note : null,
			],
			['id' => $approvalId]
		);

		$this->logger->log('approval', 'approved', 'AUDIT', 'OK', [
			'approval_id' => $approvalId,
			'approved_by' => $approvedBy,
		]);
		return true;
	}

	public function reject(int $approvalId, int $actorId, string $note = ''): bool {
		return $this->transitionPending($approvalId, self::STATUS_REJECTED, $actorId, $note);
	}

	public function cancel(int $approvalId, int $actorId, string $note = ''): bool {
		return $this->transitionPending($approvalId, self::STATUS_CANCELLED, $actorId, $note);
	}

	private function transitionPending(int $approvalId, string $newStatus, int $actorId, string $note): bool {
		$row = $this->find($approvalId);
		if ($row === null) {
			throw new \RuntimeException("Approval #{$approvalId} not found.");
		}
		if (!in_array($row['status'], [self::STATUS_DRAFT, self::STATUS_REVIEW_PENDING, self::STATUS_APPROVED], true)) {
			throw new \RuntimeException("Cannot transition approval #{$approvalId} from {$row['status']}.");
		}
		$table = Schema::table($this->wpdb, 'approvals');
		$this->wpdb->update(
			$table,
			[
				'status'        => $newStatus,
				'approved_by'   => $actorId,
				'decision_note' => $note !== '' ? $note : null,
			],
			['id' => $approvalId]
		);
		$this->logger->log('approval', strtolower($newStatus), 'AUDIT', 'OK', ['approval_id' => $approvalId]);
		return true;
	}

	/**
	 * Verify approval is valid, un-expired, and matches exact payload hash.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function assertExecutable(
		int $approvalId,
		string $actionType,
		string $targetType,
		int $targetId,
		array $payload
	): void {
		$row = $this->find($approvalId);
		if ($row === null) {
			throw new \RuntimeException("Approval #{$approvalId} not found.", 404);
		}
		if ($row['status'] !== self::STATUS_APPROVED) {
			throw new \RuntimeException("Approval #{$approvalId} is not APPROVED (status: {$row['status']}).", 403);
		}
		if (!empty($row['expires_at_utc']) && strtotime((string) $row['expires_at_utc']) < time()) {
			$this->markStatus($approvalId, self::STATUS_EXPIRED);
			throw new \RuntimeException("Approval #{$approvalId} has expired.", 403);
		}
		if ($row['action_type'] !== $actionType || $row['target_type'] !== $targetType || (int) $row['target_id'] !== $targetId) {
			throw new \RuntimeException("Approval #{$approvalId} target/action mismatch.", 403);
		}
		$expectedHash = self::hashPayload($payload);
		if (!hash_equals((string) $row['payload_hash'], $expectedHash)) {
			throw new \RuntimeException("Approval #{$approvalId} payload hash mismatch; re-approval required.", 403);
		}
	}

	/**
	 * Atomically claim an APPROVED record for execution to prevent double execution.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function claimForExecution(
		int $approvalId,
		string $actionType,
		string $targetType,
		int $targetId,
		array $payload
	): bool {
		$this->assertExecutable($approvalId, $actionType, $targetType, $targetId, $payload);
		$table = Schema::table($this->wpdb, 'approvals');
		$affected = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE id = %d AND status = %s",
				self::STATUS_EXECUTING,
				$approvalId,
				self::STATUS_APPROVED
			)
		);
		if ($affected !== 1) {
			throw new \RuntimeException("Approval #{$approvalId} could not be claimed (concurrent execution).", 409);
		}
		return true;
	}

	public function markExecuted(int $approvalId, string $receipt): void {
		$table = Schema::table($this->wpdb, 'approvals');
		$this->wpdb->update(
			$table,
			[
				'status'            => self::STATUS_EXECUTED,
				'executed_at_utc'   => gmdate('Y-m-d H:i:s'),
				'execution_receipt' => $receipt,
			],
			['id' => $approvalId]
		);
		$this->logger->log('approval', 'executed', 'AUDIT', 'OK', [
			'approval_id' => $approvalId,
			'receipt'     => $receipt,
		]);
	}

	public function markFailed(int $approvalId, string $error): void {
		$table = Schema::table($this->wpdb, 'approvals');
		$this->wpdb->update(
			$table,
			[
				'status'        => self::STATUS_FAILED,
				'decision_note' => substr($error, 0, 500),
			],
			['id' => $approvalId]
		);
		$this->logger->log('approval', 'execution_failed', 'ERROR', 'FAILED', [
			'approval_id' => $approvalId,
			'error'       => $error,
		]);
	}

	private function markStatus(int $approvalId, string $status): void {
		$table = Schema::table($this->wpdb, 'approvals');
		$this->wpdb->update($table, ['status' => $status], ['id' => $approvalId]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(int $approvalId): ?array {
		$table = Schema::table($this->wpdb, 'approvals');
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $approvalId),
			ARRAY_A
		);
		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listRecent(int $limit = 50): array {
		$table = Schema::table($this->wpdb, 'approvals');
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max(1, min(200, $limit))),
			ARRAY_A
		);
		return is_array($rows) ? $rows : [];
	}
}
