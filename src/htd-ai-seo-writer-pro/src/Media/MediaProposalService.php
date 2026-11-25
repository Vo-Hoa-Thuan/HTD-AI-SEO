<?php
declare(strict_types=1);

namespace HTD\AISEO\Media;

use HTD\AISEO\AI\AIError;
use HTD\AISEO\AI\AIException;
use HTD\AISEO\AI\Capability;
use HTD\AISEO\AI\ProviderRegistry;
use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Media & Video proposal engine (Media Library, capability-checked AI image, safe YouTube embed).
 * Never mutates WordPress Media Library on LIVE when READ_ONLY is enabled.
 */
final class MediaProposalService {
	public function __construct(
		private readonly object $wpdb,
		private readonly ImageValidator $validator,
		private readonly ProviderRegistry $providers,
		private readonly Logger $logger
	) {}

	public function proposeLibraryImage(int $draftId, int $attachmentId, string $url, string $altText, string $placement = 'featured'): int {
		$this->validator->validateRemoteImageUrl($url);
		return $this->saveProposal($draftId, 'library', $attachmentId, $url, $altText, '', '', $placement, 'VALIDATED');
	}

	public function assertImageProviderCapable(string $providerId): void {
		$provider = $this->providers->get($providerId);
		if (!$provider->supports(Capability::IMAGE_GENERATION)) {
			throw new AIException(AIError::UNSUPPORTED_CAPABILITY, "Provider '{$providerId}' does not support IMAGE_GENERATION.", 400);
		}
	}

	/**
	 * HTD Extension: Safe YouTube/approved video embed proposal (no arbitrary iframe/JS).
	 */
	public function proposeVideoEmbed(int $draftId, string $videoUrl, string $title, string $caption = '', string $placement = 'middle'): array {
		$parts = parse_url($videoUrl);
		if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
			throw new \InvalidArgumentException('Video URL must use HTTPS.');
		}
		$host = strtolower((string) ($parts['host'] ?? ''));
		$allowedHosts = ['www.youtube.com', 'youtube.com', 'youtu.be'];
		if (!in_array($host, $allowedHosts, true)) {
			throw new \InvalidArgumentException("Unsupported video host: {$host}. Only YouTube URLs are allowlisted.");
		}

		$embedCode = '[embed]' . esc_url_raw_safe($videoUrl) . '[/embed]';
		$id = $this->saveProposal($draftId, 'video', 0, $videoUrl, '', $caption, $title, $placement, 'VALIDATED', ['embed' => $embedCode]);

		return [
			'proposal_id' => $id,
			'video_url'   => $videoUrl,
			'title'       => $title,
			'embed'       => $embedCode,
		];
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private function saveProposal(
		int $draftId,
		string $sourceType,
		int $attachmentId,
		string $url,
		string $alt,
		string $caption,
		string $title,
		string $placement,
		string $status,
		array $meta = []
	): int {
		$table = Schema::table($this->wpdb, 'media_proposals');
		$this->wpdb->insert($table, [
			'draft_id'          => $draftId,
			'source_type'       => $sourceType,
			'attachment_id'     => $attachmentId,
			'media_url'         => $url,
			'alt_text'          => $alt,
			'caption'           => $caption,
			'image_title'       => $title,
			'placement'         => $placement,
			'validation_status' => $status,
			'metadata_json'     => (string) json_encode($meta, JSON_UNESCAPED_UNICODE),
			'created_at_utc'    => gmdate('Y-m-d H:i:s'),
		]);
		$id = (int) $this->wpdb->insert_id;
		$this->logger->log('media', 'proposal_created', 'INFO', 'OK', ['proposal_id' => $id, 'source_type' => $sourceType]);
		return $id;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listForDraft(int $draftId): array {
		$table = Schema::table($this->wpdb, 'media_proposals');
		$rows = $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$table} WHERE draft_id = %d ORDER BY id ASC", $draftId), ARRAY_A);
		return is_array($rows) ? $rows : [];
	}
}

function esc_url_raw_safe(string $url): string {
	return function_exists('esc_url_raw') ? esc_url_raw($url) : (filter_var($url, FILTER_SANITIZE_URL) ?: '');
}
