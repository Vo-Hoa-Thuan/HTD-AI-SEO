<?php
declare(strict_types=1);

namespace HTD\AISEO\WooCommerce;

use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Security\MutationGuard;
use HTD\AISEO\Security\Sanitizer;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * WooCommerce Product Engine with strict Product Data Protection and concurrent modification detection.
 */
final class ProductEngine {
	/** @var string[] Protected fields AI/proposal cannot mutate */
	private const PROTECTED_FIELDS = [
		'id', 'sku', 'url', 'slug', 'regular_price', 'sale_price',
		'stock_quantity', 'stock_status', 'type', 'variations', 'attributes', 'categories', 'canonical',
	];

	public function __construct(
		private readonly object $wpdb,
		private readonly WooCommerceAdapter $wc,
		private readonly MasterProductRepository $master,
		private readonly ApprovalEngine $approvals,
		private readonly MutationGuard $guard,
		private readonly Logger $logger
	) {}

	public static function computeStateHash(array $product): string {
		$relevant = [
			'id'                => (int) ($product['id'] ?? 0),
			'sku'               => (string) ($product['sku'] ?? ''),
			'regular_price'     => (string) ($product['regular_price'] ?? ''),
			'stock_status'      => (string) ($product['stock_status'] ?? ''),
			'description'       => (string) ($product['description'] ?? ''),
			'short_description' => (string) ($product['short_description'] ?? ''),
		];
		return hash('sha256', (string) json_encode($relevant, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * Stage a product description/SEO proposal with snapshot, conflict check, and approval request.
	 *
	 * @param array<string, mixed> $product Current product state
	 * @param array<string, mixed> $proposed Proposed fields
	 * @return array<string, mixed>
	 */
	public function proposeUpdate(array $product, array $proposed, int $actorId = 0): array {
		$productId = (int) ($product['id'] ?? 0);
		if ($productId <= 0) {
			throw new \InvalidArgumentException('Valid product ID is required.');
		}

		foreach (self::PROTECTED_FIELDS as $pf) {
			if (array_key_exists($pf, $proposed)) {
				throw new \RuntimeException("Protected product field '{$pf}' cannot be modified by ProductEngine.", 403);
			}
		}

		$cleanDesc = isset($proposed['description']) ? Sanitizer::sanitizeAiHtml((string) $proposed['description']) : (string) ($product['description'] ?? '');
		$cleanShort = isset($proposed['short_description']) ? Sanitizer::sanitizeAiHtml((string) $proposed['short_description']) : (string) ($product['short_description'] ?? '');

		$conflicts = $this->master->detectConflicts($product);
		$stateHash = self::computeStateHash($product);

		$proposedFields = [
			'description'       => $cleanDesc,
			'short_description' => $cleanShort,
			'seo_title'         => (string) ($proposed['seo_title'] ?? ''),
			'meta_description'  => (string) ($proposed['meta_description'] ?? ''),
		];

		$diff = [
			'description_changed'       => $cleanDesc !== (string) ($product['description'] ?? ''),
			'short_description_changed' => $cleanShort !== (string) ($product['short_description'] ?? ''),
		];

		$table = Schema::table($this->wpdb, 'product_proposals');
		$now = gmdate('Y-m-d H:i:s');
		$this->wpdb->insert($table, [
			'product_id'            => $productId,
			'proposal_type'         => 'seo_update',
			'proposed_fields_json'  => (string) json_encode($proposedFields, JSON_UNESCAPED_UNICODE),
			'snapshot_json'         => (string) json_encode($product, JSON_UNESCAPED_UNICODE),
			'state_hash'            => $stateHash,
			'diff_json'             => (string) json_encode($diff, JSON_UNESCAPED_UNICODE),
			'master_conflicts_json' => (string) json_encode($conflicts, JSON_UNESCAPED_UNICODE),
			'status'                => 'REVIEW_PENDING',
			'created_at_utc'        => $now,
			'updated_at_utc'        => $now,
		]);
		$proposalId = (int) $this->wpdb->insert_id;

		$payloadForApproval = [
			'proposal_id'     => $proposalId,
			'product_id'      => $productId,
			'state_hash'      => $stateHash,
			'proposed_fields' => $proposedFields,
		];
		$approvalId = $this->approvals->createRequest(
			actionType: 'update_product',
			targetType: 'product',
			targetId: $productId,
			payload: $payloadForApproval,
			requestedBy: $actorId,
			previousSnapshot: $product
		);
		$this->wpdb->update($table, ['approval_id' => $approvalId], ['id' => $proposalId]);

		$this->logger->log('woocommerce', 'product_proposal_created', 'AUDIT', 'OK', [
			'proposal_id' => $proposalId,
			'product_id'  => $productId,
			'conflicts'   => count($conflicts),
		]);

		return [
			'proposal_id' => $proposalId,
			'approval_id' => $approvalId,
			'state_hash'  => $stateHash,
			'conflicts'   => $conflicts,
			'diff'        => $diff,
		];
	}

	/**
	 * Execute an approved product update after verifying MutationGuard & concurrent state_hash.
	 *
	 * @param array<string, mixed> $currentProductState
	 */
	public function executeApprovedUpdate(int $proposalId, array $currentProductState): bool {
		$table = Schema::table($this->wpdb, 'product_proposals');
		$row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $proposalId), ARRAY_A);
		if (!is_array($row)) {
			throw new \RuntimeException("Product proposal #{$proposalId} not found.", 404);
		}

		// Concurrent modification detection
		$liveHash = self::computeStateHash($currentProductState);
		if (!hash_equals((string) $row['state_hash'], $liveHash)) {
			$this->wpdb->update($table, ['status' => 'CONFLICT_DETECTED', 'updated_at_utc' => gmdate('Y-m-d H:i:s')], ['id' => $proposalId]);
			throw new \RuntimeException('Concurrent modification detected on product after approval; re-review required.', 409);
		}

		$proposedFields = json_decode((string) $row['proposed_fields_json'], true) ?: [];
		$payload = [
			'proposal_id'     => $proposalId,
			'product_id'      => (int) $row['product_id'],
			'state_hash'      => (string) $row['state_hash'],
			'proposed_fields' => $proposedFields,
		];
		$approvalId = (int) $row['approval_id'];

		$this->guard->assertCanMutate('update_product', 'product', (int) $row['product_id'], $payload, $approvalId);
		$this->approvals->claimForExecution($approvalId, 'update_product', 'product', (int) $row['product_id'], $payload);

		try {
			$ok = $this->wc->updateDescriptionFields((int) $row['product_id'], $proposedFields);
			if (!$ok && !$this->wc->isAvailable()) {
				// In test double without WC runtime, mark as simulated only if explicitly mocked
				throw new \RuntimeException('WooCommerce runtime unavailable for LIVE product update.', 503);
			}
			$this->approvals->markExecuted($approvalId, "wc-product-{$row['product_id']}");
			$this->wpdb->update($table, ['status' => 'EXECUTED', 'updated_at_utc' => gmdate('Y-m-d H:i:s')], ['id' => $proposalId]);
			return true;
		} catch (\Throwable $e) {
			$this->approvals->markFailed($approvalId, $e->getMessage());
			$this->wpdb->update($table, ['status' => 'FAILED', 'updated_at_utc' => gmdate('Y-m-d H:i:s')], ['id' => $proposalId]);
			throw $e;
		}
	}
}
