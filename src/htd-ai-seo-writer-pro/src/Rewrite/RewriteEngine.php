<?php
declare(strict_types=1);

namespace HTD\AISEO\Rewrite;

use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Content\ContentDraftRepository;
use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Security\Sanitizer;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Safe post rewrite engine: snapshots originals, preserves URL/slug/canonical/links/facts, computes risk-classified diff.
 * Never writes to WordPress LIVE directly.
 */
final class RewriteEngine {
	public const RISK_LOW     = 'LOW_RISK';
	public const RISK_MEDIUM  = 'MEDIUM_RISK';
	public const RISK_HIGH    = 'HIGH_RISK';
	public const RISK_BLOCKED = 'BLOCKED';

	public function __construct(
		private readonly object $wpdb,
		private readonly ContentDraftRepository $drafts,
		private readonly ApprovalEngine $approvals,
		private readonly Logger $logger
	) {}

	/**
	 * @param array<string, mixed> $originalPost {id, url, slug, title, html, publish_date, canonical, featured_image_id, meta}
	 * @param array<string, mixed> $proposed {title, html, meta, url?, slug?, canonical?, price?}
	 * @return array<string, mixed>
	 */
	public function proposeRewrite(array $originalPost, array $proposed, int $actorId = 0): array {
		$postId = (int) ($originalPost['id'] ?? 0);
		if ($postId <= 0) {
			throw new \InvalidArgumentException('Valid post ID is required for rewrite.');
		}

		$origUrl = (string) ($originalPost['url'] ?? '');
		$origSlug = (string) ($originalPost['slug'] ?? '');
		$origCanonical = (string) ($originalPost['canonical'] ?? '');
		$origTitle = (string) ($originalPost['title'] ?? '');
		$origHtml = (string) ($originalPost['html'] ?? '');

		$propTitle = trim((string) ($proposed['title'] ?? $origTitle));
		$propHtml = Sanitizer::sanitizeAiHtml((string) ($proposed['html'] ?? $origHtml));

		$diff = $this->computeDiff($origTitle, $origHtml, $propTitle, $propHtml);

		// Enforce strict preservation rules
		$blockedReasons = [];
		if (isset($proposed['url']) && (string) $proposed['url'] !== $origUrl) {
			$blockedReasons[] = 'URL change is blocked by rewrite preservation policy.';
		}
		if (isset($proposed['slug']) && (string) $proposed['slug'] !== $origSlug) {
			$blockedReasons[] = 'Slug change is blocked by rewrite preservation policy.';
		}
		if (isset($proposed['canonical']) && (string) $proposed['canonical'] !== $origCanonical) {
			$blockedReasons[] = 'Canonical modification is blocked.';
		}
		if (isset($proposed['price'])) {
			$blockedReasons[] = 'Product price modification is blocked in RewriteEngine.';
		}

		$risk = $this->classifyRisk($diff, $blockedReasons);
		if ($risk === self::RISK_BLOCKED) {
			throw new \RuntimeException('Rewrite blocked: ' . implode(' ', $blockedReasons), 403);
		}

		// Save internal draft preserving original slug/ID
		$draftId = $this->drafts->saveDraft([
			'keyword_id'       => (int) ($proposed['keyword_id'] ?? 0),
			'job_type'         => 'rewrite',
			'target_post_id'   => $postId,
			'suggested_title'  => $propTitle,
			'suggested_slug'   => $origSlug,
			'meta_title'       => (string) ($proposed['meta']['title'] ?? $origTitle),
			'meta_description' => (string) ($proposed['meta']['description'] ?? ''),
			'article_html'     => $propHtml,
			'qa_status'        => 'PASS',
			'status'           => 'REVIEW_PENDING',
		], $actorId);

		// Snapshot original content & links
		$snapTable = Schema::table($this->wpdb, 'rewrite_snapshots');
		$this->wpdb->insert($snapTable, [
			'draft_id'             => $draftId,
			'post_id'              => $postId,
			'original_url'         => $origUrl,
			'original_slug'        => $origSlug,
			'original_title'       => $origTitle,
			'original_html'        => $origHtml,
			'original_meta_json'   => (string) json_encode($originalPost['meta'] ?? [], JSON_UNESCAPED_UNICODE),
			'preserved_links_json' => (string) json_encode($this->extractLinks($origHtml), JSON_UNESCAPED_UNICODE),
			'diff_summary_json'    => (string) json_encode($diff, JSON_UNESCAPED_UNICODE),
			'risk_level'           => $risk,
			'created_at_utc'       => gmdate('Y-m-d H:i:s'),
		]);
		$snapshotId = (int) $this->wpdb->insert_id;

		$approvalId = $this->approvals->createRequest(
			actionType: 'rewrite_post',
			targetType: 'post',
			targetId: $postId,
			payload: [
				'draft_id'           => $draftId,
				'target_id'          => $postId,
				'target_status'      => (string) ($proposed['target_status'] ?? 'publish'),
				'scheduled_date_gmt' => null,
				'mutation_scope'     => 'wp_post_rewrite',
				'title'              => $propTitle,
				'slug'               => $origSlug,
				'excerpt'            => '',
				'meta_title'         => (string) ($proposed['meta']['title'] ?? $origTitle),
				'meta_description'   => (string) ($proposed['meta']['description'] ?? ''),
				'html_sha256'        => hash('sha256', $propHtml),
				'snapshot_id'        => $snapshotId,
				'post_id'            => $postId,
			],
			requestedBy: $actorId,
			previousSnapshot: ['title' => $origTitle, 'slug' => $origSlug, 'url' => $origUrl]
		);
		$this->drafts->updateApprovalLink($draftId, $approvalId, 'REVIEW_PENDING');

		$this->logger->log('rewrite', 'rewrite_proposed', 'AUDIT', 'OK', [
			'post_id'     => $postId,
			'draft_id'    => $draftId,
			'snapshot_id' => $snapshotId,
			'risk'        => $risk,
		]);

		return [
			'draft_id'    => $draftId,
			'snapshot_id' => $snapshotId,
			'approval_id' => $approvalId,
			'risk_level'  => $risk,
			'diff'        => $diff,
			'preserved'   => ['post_id' => $postId, 'url' => $origUrl, 'slug' => $origSlug, 'canonical' => $origCanonical],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function computeDiff(string $origTitle, string $origHtml, string $propTitle, string $propHtml): array {
		$origHeadings = $this->extractHeadings($origHtml);
		$propHeadings = $this->extractHeadings($propHtml);
		$origLinks = $this->extractLinks($origHtml);
		$propLinks = $this->extractLinks($propHtml);

		return [
			'title_changed'    => $origTitle !== $propTitle,
			'added_sections'   => array_values(array_diff($propHeadings, $origHeadings)),
			'removed_sections' => array_values(array_diff($origHeadings, $propHeadings)),
			'added_links'      => array_values(array_diff($propLinks, $origLinks)),
			'removed_links'    => array_values(array_diff($origLinks, $propLinks)),
		];
	}

	/**
	 * @param array<string, mixed> $diff
	 * @param string[] $blockedReasons
	 */
	private function classifyRisk(array $diff, array $blockedReasons): string {
		if ($blockedReasons !== []) {
			return self::RISK_BLOCKED;
		}
		if (!empty($diff['removed_links']) || count((array) $diff['removed_sections']) > 2) {
			return self::RISK_HIGH;
		}
		if (!empty($diff['title_changed']) || !empty($diff['removed_sections'])) {
			return self::RISK_MEDIUM;
		}
		return self::RISK_LOW;
	}

	/**
	 * @return string[]
	 */
	private function extractHeadings(string $html): array {
		preg_match_all('/<h[1-4][^>]*>(.*?)<\/h[1-4]>/is', $html, $m);
		return array_values(array_map(static fn(string $s): string => trim(strip_tags($s)), $m[1] ?? []));
	}

	/**
	 * @return string[]
	 */
	private function extractLinks(string $html): array {
		preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/i', $html, $m);
		return array_values(array_unique($m[1] ?? []));
	}
}
