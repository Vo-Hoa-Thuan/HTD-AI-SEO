<?php
declare(strict_types=1);

namespace HTD\AISEO\Growth;

use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Brand\BrandProfile;
use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\WooCommerce\MasterProductRepository;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Configurable Category SEO Engine (prioritizes Camera hành trình ô tô & Android Box ô tô).
 * Read-only on canonical/robots/redirects; stages proposals through ApprovalEngine.
 */
final class CategorySEOEngine {
	public function __construct(
		private readonly object $wpdb,
		private readonly BrandProfile $brand,
		private readonly MasterProductRepository $master,
		private readonly ApprovalEngine $approvals,
		private readonly Logger $logger
	) {}

	/**
	 * @param array<string, mixed> $categoryData {slug, url, title, h1, meta_description, intro_html, product_count, faq_count, internal_links, canonical, robots}
	 * @return array<string, mixed>
	 */
	public function auditAndPropose(array $categoryData, int $actorId = 0): array {
		$slug = trim((string) ($categoryData['slug'] ?? ''));
		$url = trim((string) ($categoryData['url'] ?? ''));
		if ($slug === '' || $url === '') {
			throw new \InvalidArgumentException('Category slug and url are required.');
		}

		$issues = [];
		$proposals = [];

		$metaDesc = trim((string) ($categoryData['meta_description'] ?? ''));
		if ($metaDesc === '' || strlen($metaDesc) < 100) {
			$issues[] = 'Missing or short category meta description.';
			$proposals[] = [
				'field'    => 'meta_description',
				'existing' => $metaDesc,
				'proposed' => "Khám phá {$slug} chính hãng tại {$this->brand->get()['brand_name']} — thông số minh bạch, hỗ trợ lắp đặt chuẩn kỹ thuật.",
				'reason'   => 'Improve SERP snippet clarity without fabricating specs.',
				'risk'     => 'LOW_RISK',
				'priority' => 'P1',
			];
		}

		if (empty($categoryData['intro_html']) || strlen(strip_tags((string) $categoryData['intro_html'])) < 120) {
			$issues[] = 'Category introduction content is thin or missing.';
			$proposals[] = [
				'field'    => 'category_intro',
				'existing' => (string) ($categoryData['intro_html'] ?? ''),
				'proposed' => 'NEEDS_AI_GENERATION_FROM_VERIFIED_MASTER_PRODUCTS',
				'reason'   => 'Provide buying guide & comparison table grounded in MASTER PRODUCT HTD.',
				'risk'     => 'MEDIUM_RISK',
				'priority' => 'P1',
			];
		}

		if ((int) ($categoryData['faq_count'] ?? 0) === 0) {
			$issues[] = 'No FAQ section found on category page.';
			$proposals[] = [
				'field'    => 'faq',
				'existing' => '0',
				'proposed' => 'Add verified FAQ for installation, compatibility, and warranty.',
				'reason'   => 'Address buyer questions cleanly.',
				'risk'     => 'LOW_RISK',
				'priority' => 'P2',
			];
		}

		$audit = [
			'issues'          => $issues,
			'canonical_ro'    => (string) ($categoryData['canonical'] ?? ''),
			'robots_ro'       => (string) ($categoryData['robots'] ?? ''),
			'product_count'   => (int) ($categoryData['product_count'] ?? 0),
			'internal_links'  => (int) ($categoryData['internal_links'] ?? 0),
		];

		$table = Schema::table($this->wpdb, 'category_audits');
		$this->wpdb->insert($table, [
			'category_slug'  => $slug,
			'category_url'   => $url,
			'snapshot_json'  => (string) json_encode($categoryData, JSON_UNESCAPED_UNICODE),
			'audit_json'     => (string) json_encode($audit, JSON_UNESCAPED_UNICODE),
			'proposals_json' => (string) json_encode($proposals, JSON_UNESCAPED_UNICODE),
			'status'         => 'AUDITED',
			'created_at_utc' => gmdate('Y-m-d H:i:s'),
		]);
		$auditId = (int) $this->wpdb->insert_id;

		$approvalId = null;
		if ($proposals !== []) {
			$approvalId = $this->approvals->createRequest(
				actionType: 'category_optimization',
				targetType: 'category',
				targetId: $auditId,
				payload: ['audit_id' => $auditId, 'slug' => $slug, 'url' => $url, 'proposals' => $proposals],
				requestedBy: $actorId
			);
			$this->wpdb->update($table, ['approval_id' => $approvalId], ['id' => $auditId]);
		}

		$this->logger->log('category_seo', 'category_audited', 'AUDIT', 'OK', ['slug' => $slug, 'issues' => count($issues)]);

		return [
			'audit_id'    => $auditId,
			'approval_id' => $approvalId,
			'audit'       => $audit,
			'proposals'   => $proposals,
		];
	}
}
