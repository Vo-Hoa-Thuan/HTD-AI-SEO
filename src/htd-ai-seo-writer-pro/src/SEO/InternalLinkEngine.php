<?php
declare(strict_types=1);

namespace HTD\AISEO\SEO;

use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Brand\BrandProfile;
use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Security\MutationGuard;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Full Internal Link Engine: indexing, orphan detection, proposal repository, and guarded execution.
 * Never fabricates URLs or auto-edits existing links without approval.
 */
final class InternalLinkEngine {
	public function __construct(
		private readonly object $wpdb,
		private readonly BrandProfile $brand,
		private readonly InternalLinkProposalEngine $proposer,
		private readonly ApprovalEngine $approvals,
		private readonly MutationGuard $guard,
		private readonly Logger $logger
	) {}

	/**
	 * @param array<int, string> $categorySlugs
	 * @param array<int, string> $outboundLinks
	 */
	public function indexPost(int $postId, string $url, string $title, string $postType = 'post', array $categorySlugs = [], array $outboundLinks = []): void {
		if ($postId <= 0 || !filter_var($url, FILTER_VALIDATE_URL)) {
			throw new \InvalidArgumentException('Valid postId and URL required for link index.');
		}
		$table = Schema::table($this->wpdb, 'link_index');
		$now = gmdate('Y-m-d H:i:s');
		$existing = $this->wpdb->get_row($this->wpdb->prepare("SELECT id FROM {$table} WHERE post_id = %d LIMIT 1", $postId), ARRAY_A);
		$data = [
			'post_id'             => $postId,
			'url'                 => $url,
			'title'               => $title,
			'post_type'           => $postType,
			'category_slugs_json' => (string) json_encode(array_values($categorySlugs), JSON_UNESCAPED_UNICODE),
			'outbound_links_json' => (string) json_encode(array_values($outboundLinks), JSON_UNESCAPED_UNICODE),
			'updated_at_utc'      => $now,
		];
		if (is_array($existing)) {
			$this->wpdb->update($table, $data, ['post_id' => $postId]);
		} else {
			$this->wpdb->insert($table, $data);
		}
	}

	/**
	 * Detect orphan posts (zero inbound links from indexed site content).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function detectOrphanContent(): array {
		$table = Schema::table($this->wpdb, 'link_index');
		$rows = $this->wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A);
		if (!is_array($rows) || $rows === []) {
			return [];
		}
		$inboundCounts = [];
		foreach ($rows as $r) {
			$inboundCounts[(string) $r['url']] = 0;
		}
		foreach ($rows as $r) {
			$out = json_decode((string) ($r['outbound_links_json'] ?? '[]'), true);
			if (is_array($out)) {
				foreach ($out as $link) {
					$norm = rtrim((string) $link, '/');
					foreach ($inboundCounts as $u => $c) {
						if (rtrim($u, '/') === $norm) {
							$inboundCounts[$u]++;
						}
					}
				}
			}
		}
		$orphans = [];
		foreach ($rows as $r) {
			if (($inboundCounts[(string) $r['url']] ?? 0) === 0) {
				$orphans[] = $r;
			}
		}
		return $orphans;
	}

	/**
	 * Create & persist an internal link proposal with human approval request.
	 *
	 * @return array<string, mixed>
	 */
	public function createProposal(int $sourcePostId, string $sourceUrl, string $topic, int $maxLinks = 3, int $actorId = 0): array {
		$idxTable = Schema::table($this->wpdb, 'link_index');
		$rows = $this->wpdb->get_results("SELECT post_id, url, title, post_type FROM {$idxTable} ORDER BY id DESC LIMIT 100", ARRAY_A);
		$candidates = [];
		if (is_array($rows)) {
			foreach ($rows as $r) {
				$candidates[] = [
					'post_id' => (int) $r['post_id'],
					'url'     => (string) $r['url'],
					'title'   => (string) $r['title'],
					'type'    => (string) $r['post_type'],
				];
			}
		}

		$suggestions = $this->proposer->propose($topic, $sourceUrl, $candidates, $maxLinks);
		$propTable = Schema::table($this->wpdb, 'link_proposals');
		$now = gmdate('Y-m-d H:i:s');
		$proposalIds = [];

		foreach ($suggestions as $s) {
			$this->wpdb->insert($propTable, [
				'source_post_id'  => $sourcePostId,
				'target_post_id'  => 0,
				'target_url'      => $s['url'],
				'anchor_text'     => $s['anchor_text'],
				'relevance_score' => $s['score'],
				'placement'       => $s['placement'],
				'status'          => 'REVIEW_PENDING',
				'created_at_utc'  => $now,
			]);
			$proposalIds[] = (int) $this->wpdb->insert_id;
		}

		$approvalId = $this->approvals->createRequest(
			actionType: 'insert_internal_links',
			targetType: 'post',
			targetId: $sourcePostId,
			payload: ['source_post_id' => $sourcePostId, 'proposal_ids' => $proposalIds, 'links' => $suggestions],
			requestedBy: $actorId
		);

		foreach ($proposalIds as $pid) {
			$this->wpdb->update($propTable, ['approval_id' => $approvalId], ['id' => $pid]);
		}

		$this->logger->log('internal_links', 'proposals_created', 'AUDIT', 'OK', [
			'source_post_id' => $sourcePostId,
			'count'          => count($proposalIds),
			'approval_id'    => $approvalId,
		]);

		return [
			'approval_id'  => $approvalId,
			'proposal_ids' => $proposalIds,
			'suggestions'  => $suggestions,
		];
	}

	/**
	 * Execute approved internal link insertion via MutationGuard.
	 *
	 * @param array<int, array<string, mixed>> $suggestions
	 * @param callable|null $writer Optional test double
	 */
	public function executeApproved(int $sourcePostId, int $approvalId, array $proposalIds, array $suggestions, ?callable $writer = null): bool {
		$payload = ['source_post_id' => $sourcePostId, 'proposal_ids' => $proposalIds, 'links' => $suggestions];
		$this->guard->assertCanMutate('insert_internal_links', 'post', $sourcePostId, $payload, $approvalId);
		$this->approvals->claimForExecution($approvalId, 'insert_internal_links', 'post', $sourcePostId, $payload);

		try {
			if ($writer !== null) {
				$writer($sourcePostId, $suggestions);
			} elseif (function_exists('get_post')) {
				$post = get_post($sourcePostId);
				if (!$post) {
					throw new \RuntimeException("Post #{$sourcePostId} not found.");
				}
				$content = (string) $post->post_content;
				$append = "\n<ul>\n";
				foreach ($suggestions as $s) {
					$append .= '<li><a href="' . esc_url((string) $s['url']) . '">' . esc_html((string) $s['anchor_text']) . "</a></li>\n";
				}
				$append .= "</ul>";
				wp_update_post(['ID' => $sourcePostId, 'post_content' => $content . $append]);
			} else {
				throw new \RuntimeException('WordPress post API unavailable.', 503);
			}

			$this->approvals->markExecuted($approvalId, "links-post-{$sourcePostId}");
			$propTable = Schema::table($this->wpdb, 'link_proposals');
			foreach ($proposalIds as $pid) {
				$this->wpdb->update($propTable, ['status' => 'EXECUTED'], ['id' => $pid]);
			}
			return true;
		} catch (\Throwable $e) {
			$this->approvals->markFailed($approvalId, $e->getMessage());
			throw $e;
		}
	}
}
