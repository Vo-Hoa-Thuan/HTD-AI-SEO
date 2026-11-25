<?php
declare(strict_types=1);

namespace HTD\AISEO\Queue;

use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Idempotent job queue with lease locking, heartbeat, exponential backoff, and dead-letter recovery.
 */
final class QueueEngine {
	public const STATUS_PENDING     = 'PENDING';
	public const STATUS_RUNNING     = 'RUNNING';
	public const STATUS_COMPLETED   = 'COMPLETED';
	public const STATUS_FAILED      = 'FAILED';
	public const STATUS_DEAD_LETTER = 'DEAD_LETTER';
	public const STATUS_CANCELLED   = 'CANCELLED';

	public function __construct(
		private readonly object $wpdb,
		private readonly SettingsRegistry $settings,
		private readonly Logger $logger
	) {}

	/**
	 * Enqueue a job idempotently. Returns existing job ID if idempotency_key already exists.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function enqueue(
		string $jobType,
		array $payload,
		string $idempotencyKey,
		int $priority = 5,
		int $maxAttempts = 3
	): int {
		if ((bool) $this->settings->get('emergency_stop', false)) {
			throw new \RuntimeException('Queue enqueue blocked: EMERGENCY_STOP is active.', 423);
		}

		$table = Schema::table($this->wpdb, 'queue');
		$existingId = $this->wpdb->get_var(
			$this->wpdb->prepare("SELECT id FROM {$table} WHERE idempotency_key = %s LIMIT 1", $idempotencyKey)
		);
		if ($existingId !== null) {
			return (int) $existingId;
		}

		$now = gmdate('Y-m-d H:i:s');
		$this->wpdb->insert($table, [
			'idempotency_key' => $idempotencyKey,
			'job_type'        => $jobType,
			'payload_json'    => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'status'          => self::STATUS_PENDING,
			'priority'        => max(1, min(10, $priority)),
			'attempts'        => 0,
			'max_attempts'    => max(1, $maxAttempts),
			'next_attempt_at' => $now,
			'created_at_utc'  => $now,
			'updated_at_utc'  => $now,
		]);
		$id = (int) $this->wpdb->insert_id;

		$this->logger->log('queue', 'enqueued', 'INFO', 'OK', [
			'job_id'          => $id,
			'job_type'        => $jobType,
			'idempotency_key' => $idempotencyKey,
		]);
		return $id;
	}

	/**
	 * Atomically claim the next eligible job with a lease lock.
	 *
	 * @return array<string, mixed>|null
	 */
	public function claimNext(string $workerId, int $leaseSeconds = 120): ?array {
		if ((bool) $this->settings->get('emergency_stop', false)) {
			return null;
		}

		$this->recoverExpiredLeases();

		$table = Schema::table($this->wpdb, 'queue');
		$now = gmdate('Y-m-d H:i:s');
		$leaseUntil = gmdate('Y-m-d H:i:s', time() + max(10, $leaseSeconds));

		$candidate = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN (%s, %s) AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY priority ASC, id ASC LIMIT 1",
				self::STATUS_PENDING,
				self::STATUS_FAILED,
				$now
			),
			ARRAY_A
		);
		if (!is_array($candidate)) {
			return null;
		}

		$jobId = (int) $candidate['id'];
		$affected = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET status = %s, lock_owner = %s, lease_expires_at = %s, heartbeat_at = %s, attempts = attempts + 1, updated_at_utc = %s WHERE id = %d AND status IN (%s, %s)",
				self::STATUS_RUNNING,
				$workerId,
				$leaseUntil,
				$now,
				$now,
				$jobId,
				self::STATUS_PENDING,
				self::STATUS_FAILED
			)
		);
		if ($affected !== 1) {
			return null;
		}

		$attemptsTable = Schema::table($this->wpdb, 'job_attempts');
		$this->wpdb->insert($attemptsTable, [
			'job_id'         => $jobId,
			'attempt_no'     => ((int) $candidate['attempts']) + 1,
			'lock_owner'     => $workerId,
			'status'         => 'RUNNING',
			'started_at_utc' => $now,
		]);

		return $this->find($jobId);
	}

	public function heartbeat(int $jobId, string $workerId, int $extendSeconds = 120): bool {
		$table = Schema::table($this->wpdb, 'queue');
		$now = gmdate('Y-m-d H:i:s');
		$leaseUntil = gmdate('Y-m-d H:i:s', time() + max(10, $extendSeconds));
		$affected = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET heartbeat_at = %s, lease_expires_at = %s, updated_at_utc = %s WHERE id = %d AND status = %s AND lock_owner = %s",
				$now,
				$leaseUntil,
				$now,
				$jobId,
				self::STATUS_RUNNING,
				$workerId
			)
		);
		return $affected === 1;
	}

	public function complete(int $jobId, string $workerId): bool {
		$table = Schema::table($this->wpdb, 'queue');
		$now = gmdate('Y-m-d H:i:s');
		$affected = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET status = %s, lock_owner = NULL, lease_expires_at = NULL, updated_at_utc = %s WHERE id = %d AND status = %s AND lock_owner = %s",
				self::STATUS_COMPLETED,
				$now,
				$jobId,
				self::STATUS_RUNNING,
				$workerId
			)
		);
		if ($affected === 1) {
			$this->finishAttempt($jobId, $workerId, 'COMPLETED', null);
			return true;
		}
		return false;
	}

	public function fail(int $jobId, string $workerId, string $error, int $baseBackoffSeconds = 30): string {
		$job = $this->find($jobId);
		if ($job === null || $job['lock_owner'] !== $workerId) {
			return 'IGNORED';
		}
		$attempts = (int) $job['attempts'];
		$maxAttempts = (int) $job['max_attempts'];
		$newStatus = ($attempts >= $maxAttempts) ? self::STATUS_DEAD_LETTER : self::STATUS_FAILED;
		$backoff = $baseBackoffSeconds * (2 ** max(0, $attempts - 1));
		$nextAt = $newStatus === self::STATUS_FAILED ? gmdate('Y-m-d H:i:s', time() + $backoff) : null;
		$now = gmdate('Y-m-d H:i:s');

		$table = Schema::table($this->wpdb, 'queue');
		$this->wpdb->update(
			$table,
			[
				'status'           => $newStatus,
				'lock_owner'       => null,
				'lease_expires_at' => null,
				'next_attempt_at'  => $nextAt,
				'last_error'       => substr($error, 0, 500),
				'updated_at_utc'   => $now,
			],
			['id' => $jobId]
		);
		$this->finishAttempt($jobId, $workerId, $newStatus, $error);
		return $newStatus;
	}

	public function cancel(int $jobId): bool {
		$table = Schema::table($this->wpdb, 'queue');
		$now = gmdate('Y-m-d H:i:s');
		$affected = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET status = %s, lock_owner = NULL, lease_expires_at = NULL, updated_at_utc = %s WHERE id = %d AND status IN (%s, %s, %s)",
				self::STATUS_CANCELLED,
				$now,
				$jobId,
				self::STATUS_PENDING,
				self::STATUS_FAILED,
				self::STATUS_RUNNING
			)
		);
		return $affected === 1;
	}

	public function retry(int $jobId): bool {
		$table = Schema::table($this->wpdb, 'queue');
		$now = gmdate('Y-m-d H:i:s');
		$affected = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET status = %s, lock_owner = NULL, lease_expires_at = NULL, next_attempt_at = %s, updated_at_utc = %s WHERE id = %d AND status IN (%s, %s, %s)",
				self::STATUS_PENDING,
				$now,
				$now,
				$jobId,
				self::STATUS_FAILED,
				self::STATUS_DEAD_LETTER,
				self::STATUS_CANCELLED
			)
		);
		return $affected === 1;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listRecent(int $limit = 30): array {
		$table = Schema::table($this->wpdb, 'queue');
		$rows = $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max(1, min(200, $limit))), ARRAY_A);
		return is_array($rows) ? $rows : [];
	}

	public function recoverExpiredLeases(): int {
		$table = Schema::table($this->wpdb, 'queue');
		$now = gmdate('Y-m-d H:i:s');
		$res = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET status = CASE WHEN attempts >= max_attempts THEN %s ELSE %s END, lock_owner = NULL, lease_expires_at = NULL, last_error = %s, updated_at_utc = %s WHERE status = %s AND lease_expires_at IS NOT NULL AND lease_expires_at < %s",
				self::STATUS_DEAD_LETTER,
				self::STATUS_FAILED,
				'Lease expired before completion',
				$now,
				self::STATUS_RUNNING,
				$now
			)
		);
		return is_int($res) ? $res : 0;
	}

	private function finishAttempt(int $jobId, string $workerId, string $status, ?string $error): void {
		$attemptsTable = Schema::table($this->wpdb, 'job_attempts');
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$attemptsTable} SET status = %s, error_message = %s, finished_at_utc = %s WHERE job_id = %d AND lock_owner = %s AND finished_at_utc IS NULL",
				$status,
				$error !== null ? substr($error, 0, 500) : null,
				gmdate('Y-m-d H:i:s'),
				$jobId,
				$workerId
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(int $jobId): ?array {
		$table = Schema::table($this->wpdb, 'queue');
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $jobId),
			ARRAY_A
		);
		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<string, int>
	 */
	public function countsByStatus(): array {
		$table = Schema::table($this->wpdb, 'queue');
		$rows = $this->wpdb->get_results("SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status", ARRAY_A);
		$counts = [];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$counts[(string) $row['status']] = (int) $row['cnt'];
			}
		}
		return $counts;
	}
}
