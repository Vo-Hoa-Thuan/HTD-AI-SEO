<?php
declare(strict_types=1);

namespace HTD\AISEO\Media;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Reads WordPress Media Library attachments safely (read-only).
 */
final class MediaLibraryReader {
	/**
	 * @return array<int, array{id: int, title: string, url: string, alt: string, mime: string}>
	 */
	public function search(string $query = '', int $limit = 20, array $fixtureAttachments = []): array {
		if (!function_exists('get_posts')) {
			$out = [];
			foreach ($fixtureAttachments as $att) {
				if ($query !== '' && stripos((string) ($att['title'] ?? ''), $query) === false) {
					continue;
				}
				$out[] = $att;
				if (count($out) >= $limit) {
					break;
				}
			}
			return $out;
		}

		$posts = get_posts([
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => max(1, min(50, $limit)),
			's'              => $query,
		]);
		$out = [];
		foreach ($posts as $p) {
			$id = (int) $p->ID;
			$out[] = [
				'id'    => $id,
				'title' => (string) $p->post_title,
				'url'   => (string) wp_get_attachment_url($id),
				'alt'   => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
				'mime'  => (string) $p->post_mime_type,
			];
		}
		return $out;
	}
}
