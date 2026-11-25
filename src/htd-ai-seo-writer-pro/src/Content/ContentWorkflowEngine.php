<?php
declare(strict_types=1);

namespace HTD\AISEO\Content;

use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Contracts\ContentGeneratorInterface;
use HTD\AISEO\Keyword\KeywordIntelligence;
use HTD\AISEO\Security\Sanitizer;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Orchestrates structured SEO content generation, QA, and staging for human approval.
 * Never auto-publishes to WordPress LIVE.
 */
final class ContentWorkflowEngine implements ContentGeneratorInterface {
	public const WORKFLOW_TYPES = [
		'seo_informational', 'how_to_guide', 'product_comparison', 'buying_guide',
		'verified_review', 'installation_log', 'troubleshooting', 'faq_article',
		'tech_explainer', 'product_intro', 'category_content', 'content_refresh',
	];

	public function __construct(
		private readonly ContentDraftRepository $drafts,
		private readonly ContentQAEngine $qa,
		private readonly ApprovalEngine $approvals,
		private readonly KeywordIntelligence $intelligence
	) {}

	/**
	 * Build a structured SEO content contract from validated AI/fixture output + MASTER PRODUCT HTD check.
	 *
	 * @param array<string, mixed> $jobPayload
	 * @return array<string, mixed>
	 */
	public function generateDraft(array $jobPayload): array {
		$keyword = trim((string) ($jobPayload['primary_keyword'] ?? ''));
		if ($keyword === '') {
			throw new \InvalidArgumentException('primary_keyword is required.');
		}

		$intentInfo = $this->intelligence->classifyIntentHeuristic($keyword);
		$warnings = [];

		// MASTER PRODUCT HTD conflict check
		$masterProduct = is_array($jobPayload['master_product'] ?? null) ? $jobPayload['master_product'] : null;
		$siteProduct = is_array($jobPayload['site_product'] ?? null) ? $jobPayload['site_product'] : null;
		if ($masterProduct !== null && $siteProduct !== null) {
			foreach ($masterProduct as $k => $v) {
				if (isset($siteProduct[$k]) && (string) $siteProduct[$k] !== (string) $v) {
					$warnings[] = "MASTER PRODUCT HTD conflict on '{$k}': using approved MASTER value.";
				}
			}
		} elseif ($masterProduct === null && !empty($jobPayload['requires_product_specs'])) {
			$warnings[] = 'NEEDS_VERIFICATION: Missing MASTER PRODUCT HTD verified specifications.';
		}

		$title = trim((string) ($jobPayload['suggested_title'] ?? ucfirst($keyword)));
		$html = Sanitizer::sanitizeAiHtml((string) ($jobPayload['article_html'] ?? ''));

		$contract = [
			'keyword_id'                  => (int) ($jobPayload['keyword_id'] ?? 0),
			'job_type'                    => (string) ($jobPayload['job_type'] ?? 'new'),
			'workflow_type'               => (string) ($jobPayload['workflow_type'] ?? 'seo_informational'),
			'primary_keyword'             => $keyword,
			'secondary_keywords'          => (array) ($jobPayload['secondary_keywords'] ?? []),
			'search_intent'               => (string) ($jobPayload['search_intent'] ?? $intentInfo['intent']),
			'suggested_title'             => $title,
			'h1'                          => (string) ($jobPayload['h1'] ?? $title),
			'meta_title'                  => (string) ($jobPayload['meta_title'] ?? $title),
			'meta_description'            => (string) ($jobPayload['meta_description'] ?? ''),
			'suggested_slug'              => (string) ($jobPayload['suggested_slug'] ?? $this->slugify($title)),
			'excerpt'                     => (string) ($jobPayload['excerpt'] ?? ''),
			'article_html'                => $html,
			'heading_structure'           => $this->extractHeadings($html),
			'faq'                         => (array) ($jobPayload['faq'] ?? []),
			'tags'                        => (array) ($jobPayload['tags'] ?? []),
			'suggested_category'          => (string) ($jobPayload['suggested_category'] ?? ''),
			'internal_link_proposals'     => (array) ($jobPayload['internal_link_proposals'] ?? []),
			'external_reference_proposals'=> (array) ($jobPayload['external_reference_proposals'] ?? []),
			'featured_image_proposal'     => $jobPayload['featured_image_proposal'] ?? null,
			'image_alt_text'              => (string) ($jobPayload['image_alt_text'] ?? $keyword),
			'cta'                         => (string) ($jobPayload['cta'] ?? ''),
			'product_references'          => $masterProduct ?? [],
			'verification_warnings'       => $warnings,
			'source_references'           => (array) ($jobPayload['source_references'] ?? []),
		];

		$qaResult = $this->qa->evaluate($contract);
		$contract['qa_status'] = $qaResult['status'];
		$contract['content_qa_result'] = $qaResult;
		$contract['status'] = ($qaResult['status'] === 'FAIL') ? 'DRAFT' : 'REVIEW_PENDING';

		$draftId = $this->drafts->saveDraft($contract, (int) ($jobPayload['actor_id'] ?? 0));
		$contract['draft_id'] = $draftId;

		if ($qaResult['status'] !== 'FAIL') {
			$approvalId = $this->approvals->createRequest(
				actionType: $contract['job_type'] === 'rewrite' ? 'rewrite_post' : 'create_draft_post',
				targetType: 'post',
				targetId: (int) ($jobPayload['target_post_id'] ?? 0),
				payload: ['draft_id' => $draftId, 'title' => $title, 'slug' => $contract['suggested_slug'], 'html_sha256' => hash('sha256', $html)],
				requestedBy: (int) ($jobPayload['actor_id'] ?? 0)
			);
			$contract['approval_id'] = $approvalId;
		}

		return $contract;
	}

	/**
	 * @return string[]
	 */
	private function extractHeadings(string $html): array {
		preg_match_all('/<(h[1-4])[^>]*>(.*?)<\/\1>/is', $html, $m, PREG_SET_ORDER);
		$out = [];
		foreach ($m as $match) {
			$out[] = strtoupper($match[1]) . ': ' . trim(strip_tags($match[2]));
		}
		return $out;
	}

	private function slugify(string $text): string {
		$slug = strtolower(trim($text));
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
		return trim($slug, '-');
	}
}
