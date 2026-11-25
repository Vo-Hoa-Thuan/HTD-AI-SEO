<?php
declare(strict_types=1);

namespace HTD\AISEO\Content;

use HTD\AISEO\AI\AIOrchestrator;
use HTD\AISEO\AI\ProviderConfiguration;
use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Contracts\ContentGeneratorInterface;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Keyword\KeywordIntelligence;
use HTD\AISEO\Security\Sanitizer;
use HTD\AISEO\Settings\SettingsRegistry;

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
		private readonly KeywordIntelligence $intelligence,
		private readonly ?AIOrchestrator $ai = null,
		private readonly ?ProviderConfiguration $providerConfigs = null,
		private readonly ?CredentialVault $vault = null,
		private readonly ?SettingsRegistry $settings = null
	) {}

	/**
	 * Execute the full AI content workflow:
	 * Keyword -> AIOrchestrator -> Brief -> Outline -> Article -> SEO metadata -> QA -> Internal draft -> Approval.
	 * Returns BLOCKED_BY_CONFIGURATION if provider or credentials are missing; never fabricates content.
	 *
	 * @param array<string, mixed> $jobPayload
	 * @return array<string, mixed>
	 */
	public function runAiWorkflow(array $jobPayload): array {
		$keyword = trim((string) ($jobPayload['primary_keyword'] ?? ''));
		if ($keyword === '') {
			throw new \InvalidArgumentException('primary_keyword is required.');
		}
		if ($this->ai === null || $this->providerConfigs === null || $this->vault === null || $this->settings === null) {
			return ['status' => 'BLOCKED_BY_CONFIGURATION', 'reason' => 'AIOrchestrator dependencies not wired.'];
		}
		if ((bool) $this->settings->get('emergency_stop', false)) {
			return ['status' => 'BLOCKED_BY_CONFIGURATION', 'reason' => 'EMERGENCY_STOP is active.'];
		}
		if (!(bool) $this->settings->get('allow_external_ai_requests', false)) {
			return ['status' => 'BLOCKED_BY_CONFIGURATION', 'reason' => 'ALLOW_EXTERNAL_AI_REQUESTS is false.'];
		}

		$providerId = trim((string) ($jobPayload['provider_id'] ?? $this->settings->get('active_ai_provider', 'none')));
		if ($providerId === '' || $providerId === 'none') {
			foreach ($this->providerConfigs->all() as $pid => $cfg) {
				if (!empty($cfg['enabled'])) {
					$providerId = (string) $pid;
					break;
				}
			}
		}
		$cfg = $this->providerConfigs->get($providerId);
		if ($providerId === '' || $providerId === 'none' || empty($cfg['enabled'])) {
			return ['status' => 'BLOCKED_BY_CONFIGURATION', 'reason' => 'No enabled AI provider configured.'];
		}
		$credRef = (string) ($cfg['cred_ref'] ?? ($providerId . '_api_key'));
		$apiKey = $this->vault->getSecret($credRef);
		if ($apiKey === null || trim($apiKey) === '') {
			return ['status' => 'BLOCKED_BY_CONFIGURATION', 'reason' => "Missing credential '{$credRef}' for provider '{$providerId}'."];
		}

		$skipCap = !empty($jobPayload['skip_capability_check']);
		$secondary = implode(', ', (array) ($jobPayload['secondary_keywords'] ?? []));

		// Step 1: Keyword Brief
		$briefRes = $this->ai->executeTask($providerId, 'keyword_analysis', [
			'keyword'            => $keyword,
			'secondary_keywords' => $secondary,
		], $skipCap);
		$brief = $briefRes['validated'];

		// Step 2: Content Outline (or use caller-edited outline)
		if (!empty($jobPayload['outline']) && is_array($jobPayload['outline'])) {
			$outline = $jobPayload['outline'];
		} else {
			$outlineRes = $this->ai->executeTask($providerId, 'content_outline', [
				'keyword'            => $keyword,
				'secondary_keywords' => $secondary,
				'heading_depth'      => (string) ($jobPayload['heading_depth'] ?? 'H2,H3'),
			], $skipCap);
			$outline = $outlineRes['validated'];
		}

		// Step 3: Article Generation
		$articleRes = $this->ai->executeTask($providerId, 'seo_article', [
			'keyword'            => $keyword,
			'outline'            => (string) json_encode($outline, JSON_UNESCAPED_UNICODE),
			'secondary_keywords' => $secondary,
			'word_target'        => (string) ($jobPayload['word_target'] ?? '1200'),
		], $skipCap);
		$article = $articleRes['validated'];
		$articleHtml = trim((string) ($article['html_content'] ?? ''));
		if ($articleHtml === '') {
			throw new \RuntimeException('Provider returned empty html_content.', 502);
		}
		$title = trim((string) ($article['title'] ?? ($outline['title'] ?? ucfirst($keyword))));

		// Step 4: SEO Metadata (Meta Title & Meta Description)
		$metaTitleRes = $this->ai->executeTask($providerId, 'meta_title', [
			'keyword' => $keyword,
			'topic'   => $title,
		], $skipCap);
		$metaDescRes = $this->ai->executeTask($providerId, 'meta_description', [
			'keyword' => $keyword,
			'summary' => mb_substr(strip_tags($articleHtml), 0, 400),
		], $skipCap);

		$mergedPayload = array_merge($jobPayload, [
			'suggested_title'  => $title,
			'meta_title'       => (string) ($metaTitleRes['validated']['meta_title'] ?? $title),
			'meta_description' => (string) ($metaDescRes['validated']['meta_description'] ?? ''),
			'article_html'     => $articleHtml,
			'search_intent'    => (string) ($brief['search_intent'] ?? ($jobPayload['search_intent'] ?? '')),
			'faq'              => (array) ($article['faq'] ?? []),
			'outline'          => $outline,
		]);

		$draft = $this->generateDraft($mergedPayload);
		$draft['status_code'] = 'COMPLETED';
		$draft['provider_used'] = $providerId;
		return $draft;
	}

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
			$approvalId = 0;
			try {
				$targetPostId = (int) ($jobPayload['target_post_id'] ?? 0);
				$isRewrite = $contract['job_type'] === 'rewrite';
				$approvalPayload = [
					'draft_id'           => $draftId,
					'target_id'          => $targetPostId,
					'target_status'      => $isRewrite ? 'publish' : 'draft',
					'scheduled_date_gmt' => null,
					'mutation_scope'     => $isRewrite ? 'wp_post_rewrite' : 'wp_post_draft',
					'title'              => trim($title),
					'slug'               => trim($contract['suggested_slug']),
					'excerpt'            => trim($contract['excerpt']),
					'meta_title'         => trim($contract['meta_title']),
					'meta_description'   => trim($contract['meta_description']),
					'html_sha256'        => hash('sha256', $html),
				];
				$approvalId = $this->approvals->createRequest(
					actionType: $isRewrite ? 'rewrite_post' : 'create_draft_post',
					targetType: 'post',
					targetId: $targetPostId,
					payload: $approvalPayload,
					requestedBy: (int) ($jobPayload['actor_id'] ?? 0)
				);
				if ($approvalId <= 0 || !$this->drafts->updateApprovalLink($draftId, $approvalId, 'REVIEW_PENDING')) {
					throw new \RuntimeException("Failed to persist approval link for draft #{$draftId}.");
				}
				$contract['approval_id'] = $approvalId;
			} catch (\Throwable $e) {
				if ($approvalId > 0) {
					try {
						$this->approvals->cancel($approvalId, (int) ($jobPayload['actor_id'] ?? 0), 'Rolled back due to draft link failure');
					} catch (\Throwable) {}
				}
				$this->drafts->updateApprovalLink($draftId, null, 'DRAFT');
				throw $e;
			}
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
