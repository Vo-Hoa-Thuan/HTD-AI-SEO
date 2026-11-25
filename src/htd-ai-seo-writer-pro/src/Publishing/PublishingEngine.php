<?php
declare(strict_types=1);

namespace HTD\AISEO\Publishing;

use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Content\ContentDraftRepository;
use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Security\MutationGuard;
use HTD\AISEO\Security\Sanitizer;
use HTD\AISEO\SEO\RankMathAdapter;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Idempotent WordPress Publishing Engine gated by MutationGuard, ApprovalEngine, and Content QA.
 */
final class PublishingEngine {
	public function __construct(
		private readonly object $wpdb,
		private readonly ContentDraftRepository $drafts,
		private readonly ApprovalEngine $approvals,
		private readonly MutationGuard $guard,
		private readonly RankMathAdapter $rankMath,
		private readonly Logger $logger
	) {}

	/**
	 * Execute an approved draft/rewrite publish into WordPress (draft, publish, or future).
	 *
	 * @param callable|null $wpPostWriter Optional test-double callback(array $postData): int for non-WP unit tests
	 * @return array<string, mixed>
	 */
	public function execute(
		int $draftId,
		int $approvalId,
		string $targetStatus = 'draft',
		string $idempotencyKey = '',
		bool $isAutoPublish = false,
		?string $scheduledDateGmt = null,
		?callable $wpPostWriter = null
	): array {
		if (!in_array($targetStatus, ['draft', 'publish', 'future'], true)) {
			throw new \InvalidArgumentException("Invalid target status: {$targetStatus}", 400);
		}
		if ($targetStatus === 'future' && ($scheduledDateGmt === null || trim($scheduledDateGmt) === '' || strtotime($scheduledDateGmt) === false)) {
			throw new \InvalidArgumentException('Valid scheduledDateGmt is required when targetStatus is future.', 400);
		}

		if (function_exists('current_user_can')) {
			$reqCap = ($targetStatus === 'draft')
				? \HTD\AISEO\Support\Capabilities::MANAGE
				: \HTD\AISEO\Support\Capabilities::APPROVE;
			if (!\HTD\AISEO\Support\Capabilities::currentUserCan($reqCap)) {
				throw new \RuntimeException("Insufficient capability ({$reqCap}) to execute {$targetStatus} publishing.", 403);
			}
		}

		$draft = $this->drafts->find($draftId);
		if ($draft === null) {
			throw new \RuntimeException("Draft #{$draftId} not found.", 404);
		}
		if (($draft['qa_status'] ?? 'NOT_RUN') === 'FAIL') {
			throw new \RuntimeException("Draft #{$draftId} failed Content QA and cannot be published.", 422);
		}

		$idemKey = $idempotencyKey !== '' ? $idempotencyKey : "pub-draft-{$draftId}-appr-{$approvalId}";
		$receiptTable = Schema::table($this->wpdb, 'publishing_receipts');
		$existing = $this->wpdb->get_row(
			$this->wpdb->prepare("SELECT * FROM {$receiptTable} WHERE idempotency_key = %s LIMIT 1", $idemKey),
			ARRAY_A
		);
		if (is_array($existing)) {
			if (($existing['execution_status'] ?? '') === 'EXECUTED') {
				return [
					'idempotent_replay' => true,
					'wp_post_id'        => (int) $existing['wp_post_id'],
					'status'            => (string) $existing['post_status'],
				];
			}
			if (($existing['execution_status'] ?? '') === 'EXECUTING') {
				throw new \RuntimeException("Concurrent execution already in progress for key {$idemKey}.", 409);
			}
		}

		$jobType = (string) ($draft['job_type'] ?? 'new');
		if ($isAutoPublish) {
			$actionType = 'autopublish';
			$mutationScope = 'wp_post_autopublish';
		} elseif ($jobType === 'rewrite') {
			$actionType = 'rewrite_post';
			$mutationScope = 'wp_post_rewrite';
		} elseif ($targetStatus === 'draft') {
			$actionType = 'create_draft_post';
			$mutationScope = 'wp_post_draft';
		} elseif ($targetStatus === 'future') {
			$actionType = 'schedule_post';
			$mutationScope = 'wp_post_schedule';
		} else {
			$actionType = 'publish_post';
			$mutationScope = 'wp_post_publish';
		}

		$approvalRow = $this->approvals->find($approvalId);
		if ($approvalRow !== null && ($approvalRow['action_type'] ?? '') === 'create_draft_post' && $targetStatus !== 'draft') {
			throw new \RuntimeException('Draft approval (create_draft_post) cannot authorize publish or future status.', 403);
		}

		$targetPostId = (int) ($draft['target_post_id'] ?? 0);
		$cleanHtml = Sanitizer::sanitizeAiHtml((string) $draft['html_content']);
		$metaTitle = (string) ($draft['meta_title'] ?? '');
		$metaDesc = (string) ($draft['meta_description'] ?? '');
		$excerpt = (string) ($draft['excerpt'] ?? '');

		$payload = [
			'draft_id'           => $draftId,
			'target_id'          => $targetPostId,
			'target_status'      => $targetStatus,
			'scheduled_date_gmt' => $targetStatus === 'future' ? $scheduledDateGmt : null,
			'mutation_scope'     => $mutationScope,
			'title'              => (string) $draft['title'],
			'slug'               => (string) ($draft['slug'] ?? ''),
			'excerpt'            => $excerpt,
			'meta_title'         => $metaTitle,
			'meta_description'   => $metaDesc,
			'html_sha256'        => hash('sha256', $cleanHtml),
		];
		if ($jobType === 'rewrite' && $targetPostId > 0) {
			$snapTable = Schema::table($this->wpdb, 'rewrite_snapshots');
			$snap = $this->wpdb->get_row($this->wpdb->prepare("SELECT id FROM {$snapTable} WHERE draft_id = %d ORDER BY id DESC LIMIT 1", $draftId), ARRAY_A);
			if (is_array($snap)) {
				$payload['snapshot_id'] = (int) $snap['id'];
				$payload['post_id'] = $targetPostId;
			}
		}

		$this->guard->assertCanMutate($actionType, 'post', $targetPostId, $payload, $approvalId);
		$this->approvals->claimForExecution($approvalId, $actionType, 'post', $targetPostId, $payload);

		try {
			$postArgs = [
				'ID'           => $targetPostId,
				'post_title'   => (string) $draft['title'],
				'post_name'    => (string) ($draft['slug'] ?? ''),
				'post_content' => $cleanHtml,
				'post_excerpt' => (string) ($draft['excerpt'] ?? ''),
				'post_status'  => in_array($targetStatus, ['draft', 'publish', 'future'], true) ? $targetStatus : 'draft',
				'post_type'    => 'post',
			];
			if ($targetStatus === 'future' && $scheduledDateGmt !== null) {
				$postArgs['post_date_gmt'] = $scheduledDateGmt;
			}

			if ($wpPostWriter !== null) {
				$wpPostId = (int) $wpPostWriter($postArgs);
			} elseif (function_exists('wp_insert_post')) {
				$res = $targetPostId > 0 ? wp_update_post($postArgs, true) : wp_insert_post($postArgs, true);
				if (is_wp_error($res)) {
					throw new \RuntimeException($res->get_error_message(), 500);
				}
				$wpPostId = (int) $res;
				$this->rankMath->writeApprovedMeta($wpPostId, [
					'seo_title'        => $draft['meta_title'] ?? '',
					'meta_description' => $draft['meta_description'] ?? '',
				]);
			} else {
				throw new \RuntimeException('WordPress publishing API (wp_insert_post) unavailable.', 503);
			}

			if ($wpPostId <= 0) {
				throw new \RuntimeException('Failed to obtain valid WordPress post ID.', 500);
			}

			$receipt = "wp-post-{$wpPostId}-{$targetStatus}";
			$this->approvals->markExecuted($approvalId, $receipt);
			$this->wpdb->insert($receiptTable, [
				'idempotency_key'  => $idemKey,
				'approval_id'      => $approvalId,
				'action_type'      => $actionType,
				'target_type'      => 'post',
				'target_id'        => $targetPostId,
				'wp_post_id'       => $wpPostId,
				'post_status'      => $postArgs['post_status'],
				'execution_status' => 'EXECUTED',
				'receipt_json'     => (string) json_encode(['receipt' => $receipt], JSON_UNESCAPED_UNICODE),
				'created_at_utc'   => gmdate('Y-m-d H:i:s'),
			]);

			$this->logger->log('publishing', 'post_executed', 'AUDIT', 'OK', [
				'draft_id'   => $draftId,
				'wp_post_id' => $wpPostId,
				'status'     => $postArgs['post_status'],
			]);

			return [
				'idempotent_replay' => false,
				'wp_post_id'        => $wpPostId,
				'status'            => $postArgs['post_status'],
				'receipt'           => $receipt,
			];
		} catch (\Throwable $e) {
			$this->approvals->markFailed($approvalId, $e->getMessage());
			$this->wpdb->insert($receiptTable, [
				'idempotency_key'  => $idemKey . '-fail-' . time(),
				'approval_id'      => $approvalId,
				'action_type'      => $actionType,
				'target_type'      => 'post',
				'target_id'        => $targetPostId,
				'wp_post_id'       => 0,
				'post_status'      => $targetStatus,
				'execution_status' => 'FAILED',
				'error_message'    => substr($e->getMessage(), 0, 500),
				'created_at_utc'   => gmdate('Y-m-d H:i:s'),
			]);
			throw $e;
		}
	}

	/**
	 * Create a new payload-bound approval request for publishing/scheduling a QA-passed draft.
	 */
	public function createPublishApproval(
		int $draftId,
		string $targetStatus = 'publish',
		?string $scheduledDateGmt = null,
		int $requestedBy = 0
	): int {
		if (!in_array($targetStatus, ['draft', 'publish', 'future'], true)) {
			throw new \InvalidArgumentException("Invalid target status: {$targetStatus}", 400);
		}
		if ($targetStatus === 'future' && ($scheduledDateGmt === null || trim($scheduledDateGmt) === '' || strtotime($scheduledDateGmt) === false)) {
			throw new \InvalidArgumentException('Valid scheduledDateGmt is required for future status.', 400);
		}
		$draft = $this->drafts->find($draftId);
		if ($draft === null) {
			throw new \RuntimeException("Draft #{$draftId} not found.", 404);
		}
		if (($draft['qa_status'] ?? 'NOT_RUN') === 'FAIL') {
			throw new \RuntimeException("Draft #{$draftId} failed Content QA.", 422);
		}

		$jobType = (string) ($draft['job_type'] ?? 'new');
		$actionType = $jobType === 'rewrite'
			? 'rewrite_post'
			: ($targetStatus === 'draft' ? 'create_draft_post' : ($targetStatus === 'future' ? 'schedule_post' : 'publish_post'));
		$mutationScope = $jobType === 'rewrite'
			? 'wp_post_rewrite'
			: ($targetStatus === 'draft' ? 'wp_post_draft' : ($targetStatus === 'future' ? 'wp_post_schedule' : 'wp_post_publish'));
		$targetPostId = (int) ($draft['target_post_id'] ?? 0);
		$cleanHtml = Sanitizer::sanitizeAiHtml((string) $draft['html_content']);

		$payload = [
			'draft_id'           => $draftId,
			'target_id'          => $targetPostId,
			'target_status'      => $targetStatus,
			'scheduled_date_gmt' => $targetStatus === 'future' ? $scheduledDateGmt : null,
			'mutation_scope'     => $mutationScope,
			'title'              => (string) $draft['title'],
			'slug'               => (string) ($draft['slug'] ?? ''),
			'excerpt'            => (string) ($draft['excerpt'] ?? ''),
			'meta_title'         => (string) ($draft['meta_title'] ?? ''),
			'meta_description'   => (string) ($draft['meta_description'] ?? ''),
			'html_sha256'        => hash('sha256', $cleanHtml),
		];
		$approvalId = $this->approvals->createRequest($actionType, 'post', $targetPostId, $payload, $requestedBy);
		$this->drafts->updateApprovalLink($draftId, $approvalId, 'REVIEW_PENDING');
		return $approvalId;
	}
}
