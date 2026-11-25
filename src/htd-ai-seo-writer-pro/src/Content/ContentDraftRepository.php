<?php
declare(strict_types=1);

namespace HTD\AISEO\Content;

use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Security\Sanitizer;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Internal content draft & revision repository (never writes to wp_posts LIVE).
 */
final class ContentDraftRepository {
	public function __construct(
		private readonly object $wpdb,
		private readonly Logger $logger
	) {}

	/**
	 * @param array<string, mixed> $contract
	 */
	public function saveDraft(array $contract, int $actorId = 0): int {
		$table = Schema::table($this->wpdb, 'content_drafts');
		$now = gmdate('Y-m-d H:i:s');
		$html = Sanitizer::sanitizeAiHtml((string) ($contract['article_html'] ?? ''));

		$this->wpdb->insert($table, [
			'keyword_id'           => (int) ($contract['keyword_id'] ?? 0),
			'job_type'             => (string) ($contract['job_type'] ?? 'new'),
			'target_post_id'       => (int) ($contract['target_post_id'] ?? 0),
			'title'                => trim((string) ($contract['suggested_title'] ?? 'Untitled Draft')),
			'h1'                   => isset($contract['h1']) ? trim((string) $contract['h1']) : null,
			'meta_title'           => isset($contract['meta_title']) ? trim((string) $contract['meta_title']) : null,
			'meta_description'     => isset($contract['meta_description']) ? trim((string) $contract['meta_description']) : null,
			'slug'                 => isset($contract['suggested_slug']) ? trim((string) $contract['suggested_slug']) : null,
			'excerpt'              => isset($contract['excerpt']) ? trim((string) $contract['excerpt']) : null,
			'html_content'         => $html,
			'faq_json'             => (string) json_encode($contract['faq'] ?? [], JSON_UNESCAPED_UNICODE),
			'tags_json'            => (string) json_encode($contract['tags'] ?? [], JSON_UNESCAPED_UNICODE),
			'internal_links_json'  => (string) json_encode($contract['internal_link_proposals'] ?? [], JSON_UNESCAPED_UNICODE),
			'media_proposals_json' => (string) json_encode($contract['media_proposals'] ?? [], JSON_UNESCAPED_UNICODE),
			'structured_json'      => (string) json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'qa_status'            => (string) ($contract['qa_status'] ?? 'NOT_RUN'),
			'qa_result_json'       => (string) json_encode($contract['content_qa_result'] ?? [], JSON_UNESCAPED_UNICODE),
			'approval_id'          => $contract['approval_id'] ?? null,
			'status'               => (string) ($contract['status'] ?? 'DRAFT'),
			'created_at_utc'       => $now,
			'updated_at_utc'       => $now,
		]);
		$draftId = (int) $this->wpdb->insert_id;
		$this->addRevision($draftId, 1, (string) ($contract['suggested_title'] ?? 'Untitled Draft'), $html, $contract, 'Initial draft', $actorId);
		$this->logger->log('content', 'draft_saved', 'AUDIT', 'OK', ['draft_id' => $draftId]);
		return $draftId;
	}

	/**
	 * @param array<string, mixed> $structured
	 */
	public function addRevision(int $draftId, int $revNo, string $title, string $html, array $structured, string $summary, int $actorId = 0): int {
		$revTable = Schema::table($this->wpdb, 'content_revisions');
		$this->wpdb->insert($revTable, [
			'draft_id'        => $draftId,
			'revision_no'     => $revNo,
			'title'           => $title,
			'html_content'    => Sanitizer::sanitizeAiHtml($html),
			'structured_json' => (string) json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'change_summary'  => $summary,
			'created_by'      => $actorId,
			'created_at_utc'  => gmdate('Y-m-d H:i:s'),
		]);
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(int $draftId): ?array {
		$table = Schema::table($this->wpdb, 'content_drafts');
		$row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $draftId), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listRecent(int $limit = 50): array {
		$table = Schema::table($this->wpdb, 'content_drafts');
		$rows = $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max(1, min(200, $limit))), ARRAY_A);
		return is_array($rows) ? $rows : [];
	}

	public function exportHtml(int $draftId): string {
		$d = $this->find($draftId);
		if ($d === null) {
			throw new \RuntimeException("Draft #{$draftId} not found.");
		}
		return "<!-- HTD Draft #{$draftId}: {$d['title']} -->\n" . (string) $d['html_content'];
	}

	public function exportMarkdown(int $draftId): string {
		$d = $this->find($draftId);
		if ($d === null) {
			throw new \RuntimeException("Draft #{$draftId} not found.");
		}
		$md = "# {$d['title']}\n\n";
		$body = (string) $d['html_content'];
		$body = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "\n## $1\n", $body) ?? $body;
		$body = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "\n### $1\n", $body) ?? $body;
		$body = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $body) ?? $body;
		$body = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $body) ?? $body;
		return trim($md . strip_tags($body));
	}
}
