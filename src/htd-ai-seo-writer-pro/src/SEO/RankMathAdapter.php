<?php
declare(strict_types=1);

namespace HTD\AISEO\SEO;

use HTD\AISEO\Support\Environment;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Safe Rank Math SEO adapter.
 * Reads canonical/robots/schema in read-only mode; never modifies canonical, redirects, robots, or active schema.
 */
final class RankMathAdapter {
	public function isAvailable(): bool {
		return Environment::isRankMathActive();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function readMeta(int $postId, array $fixtureMeta = []): array {
		if (!function_exists('get_post_meta')) {
			return [
				'available'     => $this->isAvailable(),
				'title'         => (string) ($fixtureMeta['rank_math_title'] ?? ''),
				'description'   => (string) ($fixtureMeta['rank_math_description'] ?? ''),
				'focus_keyword' => (string) ($fixtureMeta['rank_math_focus_keyword'] ?? ''),
				'canonical'     => (string) ($fixtureMeta['rank_math_canonical_url'] ?? ''),
				'robots'        => $fixtureMeta['rank_math_robots'] ?? [],
			];
		}

		return [
			'available'     => $this->isAvailable(),
			'title'         => (string) get_post_meta($postId, 'rank_math_title', true),
			'description'   => (string) get_post_meta($postId, 'rank_math_description', true),
			'focus_keyword' => (string) get_post_meta($postId, 'rank_math_focus_keyword', true),
			'canonical'     => (string) get_post_meta($postId, 'rank_math_canonical_url', true),
			'robots'        => get_post_meta($postId, 'rank_math_robots', true),
		];
	}

	/**
	 * Write ONLY approved title, description, and focus_keyword.
	 *
	 * @param array<string, mixed> $approvedMeta
	 */
	public function writeApprovedMeta(int $postId, array $approvedMeta): bool {
		if (!$this->isAvailable() || !function_exists('update_post_meta')) {
			return false;
		}
		if (isset($approvedMeta['seo_title']) && $approvedMeta['seo_title'] !== '') {
			update_post_meta($postId, 'rank_math_title', sanitize_text_field((string) $approvedMeta['seo_title']));
		}
		if (isset($approvedMeta['meta_description']) && $approvedMeta['meta_description'] !== '') {
			update_post_meta($postId, 'rank_math_description', sanitize_textarea_field((string) $approvedMeta['meta_description']));
		}
		if (isset($approvedMeta['focus_keyword']) && $approvedMeta['focus_keyword'] !== '') {
			update_post_meta($postId, 'rank_math_focus_keyword', sanitize_text_field((string) $approvedMeta['focus_keyword']));
		}
		return true;
	}
}
