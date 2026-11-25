<?php
declare(strict_types=1);

namespace HTD\AISEO\Database;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Defines versioned database table schemas using $wpdb->prefix.
 */
final class Schema {
	public static function prefix(object $wpdb): string {
		$wpPrefix = property_exists($wpdb, 'prefix') ? (string) $wpdb->prefix : 'wp_';
		return $wpPrefix . 'htd_ai_seo_';
	}

	public static function table(object $wpdb, string $name): string {
		return self::prefix($wpdb) . $name;
	}

	/**
	 * @return array<string, string>
	 */
	public static function v020Tables(object $wpdb, string $charsetCollate = ''): array {
		$p = self::prefix($wpdb);
		return [
			'migrations' => "CREATE TABLE IF NOT EXISTS {$p}migrations (
				version VARCHAR(32) NOT NULL,
				checksum VARCHAR(64) NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'applied',
				applied_at_utc DATETIME NOT NULL,
				rolled_back_at_utc DATETIME NULL,
				error_message TEXT NULL,
				PRIMARY KEY (version)
			) {$charsetCollate};",

			'logs' => "CREATE TABLE IF NOT EXISTS {$p}logs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				correlation_id VARCHAR(64) NOT NULL,
				event_id VARCHAR(64) NOT NULL,
				module VARCHAR(64) NOT NULL,
				action VARCHAR(80) NOT NULL,
				actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				severity VARCHAR(20) NOT NULL DEFAULT 'INFO',
				result VARCHAR(32) NOT NULL DEFAULT 'OK',
				context_json LONGTEXT NULL,
				duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
				created_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_module_action (module, action),
				KEY idx_severity (severity),
				KEY idx_created (created_at_utc),
				KEY idx_correlation (correlation_id)
			) {$charsetCollate};",

			'queue' => "CREATE TABLE IF NOT EXISTS {$p}queue (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				idempotency_key VARCHAR(128) NOT NULL,
				job_type VARCHAR(64) NOT NULL,
				payload_json LONGTEXT NOT NULL,
				status VARCHAR(24) NOT NULL DEFAULT 'PENDING',
				priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
				lock_owner VARCHAR(64) NULL,
				lease_expires_at DATETIME NULL,
				heartbeat_at DATETIME NULL,
				attempts INT UNSIGNED NOT NULL DEFAULT 0,
				max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
				next_attempt_at DATETIME NULL,
				last_error TEXT NULL,
				created_at_utc DATETIME NOT NULL,
				updated_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_idempotency (idempotency_key),
				KEY idx_status_next (status, next_attempt_at, priority),
				KEY idx_lease (status, lease_expires_at)
			) {$charsetCollate};",

			'job_attempts' => "CREATE TABLE IF NOT EXISTS {$p}job_attempts (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id BIGINT UNSIGNED NOT NULL,
				attempt_no INT UNSIGNED NOT NULL,
				lock_owner VARCHAR(64) NOT NULL,
				status VARCHAR(24) NOT NULL,
				error_message TEXT NULL,
				started_at_utc DATETIME NOT NULL,
				finished_at_utc DATETIME NULL,
				PRIMARY KEY (id),
				KEY idx_job_id (job_id)
			) {$charsetCollate};",

			'approvals' => "CREATE TABLE IF NOT EXISTS {$p}approvals (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				action_type VARCHAR(64) NOT NULL,
				target_type VARCHAR(64) NOT NULL,
				target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				payload_json LONGTEXT NOT NULL,
				payload_hash VARCHAR(64) NOT NULL,
				previous_snapshot_json LONGTEXT NULL,
				status VARCHAR(24) NOT NULL DEFAULT 'DRAFT',
				requested_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				approved_by BIGINT UNSIGNED NULL,
				requested_at_utc DATETIME NOT NULL,
				approved_at_utc DATETIME NULL,
				expires_at_utc DATETIME NULL,
				executed_at_utc DATETIME NULL,
				execution_receipt VARCHAR(128) NULL,
				decision_note TEXT NULL,
				PRIMARY KEY (id),
				KEY idx_status (status),
				KEY idx_target (target_type, target_id),
				KEY idx_hash (payload_hash)
			) {$charsetCollate};",

			'security_events' => "CREATE TABLE IF NOT EXISTS {$p}security_events (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_type VARCHAR(64) NOT NULL,
				severity VARCHAR(20) NOT NULL,
				actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				ip_hash VARCHAR(64) NULL,
				details_json LONGTEXT NULL,
				created_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_event_type (event_type),
				KEY idx_created (created_at_utc)
			) {$charsetCollate};",
		];
	}

	/**
	 * @return array<string, string>
	 */
	public static function v040Tables(object $wpdb, string $charsetCollate = ''): array {
		$p = self::prefix($wpdb);
		return [
			'keywords' => "CREATE TABLE IF NOT EXISTS {$p}keywords (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				keyword VARCHAR(255) NOT NULL,
				secondary_keywords_json LONGTEXT NULL,
				keyword_group VARCHAR(100) NULL,
				priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
				search_intent VARCHAR(40) NOT NULL DEFAULT 'UNKNOWN',
				intent_source VARCHAR(32) NOT NULL DEFAULT 'UNKNOWN',
				target_url VARCHAR(512) NULL,
				target_post_type VARCHAR(32) NOT NULL DEFAULT 'post',
				target_category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				assigned_provider VARCHAR(32) NOT NULL DEFAULT 'openai',
				assigned_model VARCHAR(64) NULL,
				assigned_prompt VARCHAR(64) NOT NULL DEFAULT 'seo_article',
				content_language VARCHAR(10) NOT NULL DEFAULT 'vi',
				status VARCHAR(24) NOT NULL DEFAULT 'pending',
				error_message TEXT NULL,
				processing_history_json LONGTEXT NULL,
				created_at_utc DATETIME NOT NULL,
				updated_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_keyword_lang (keyword, content_language),
				KEY idx_status_prio (status, priority),
				KEY idx_group (keyword_group)
			) {$charsetCollate};",

			'keyword_mappings' => "CREATE TABLE IF NOT EXISTS {$p}keyword_mappings (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				keyword_id BIGINT UNSIGNED NOT NULL,
				target_url VARCHAR(512) NOT NULL,
				post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				slug VARCHAR(200) NULL,
				search_intent VARCHAR(40) NOT NULL DEFAULT 'UNKNOWN',
				cannibalization_status VARCHAR(32) NOT NULL DEFAULT 'CLEAR',
				data_source VARCHAR(32) NOT NULL DEFAULT 'USER_PROVIDED',
				created_at_utc DATETIME NOT NULL,
				updated_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_kw_id (keyword_id),
				KEY idx_post_id (post_id)
			) {$charsetCollate};",

			'content_drafts' => "CREATE TABLE IF NOT EXISTS {$p}content_drafts (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				keyword_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				job_type VARCHAR(32) NOT NULL DEFAULT 'new',
				target_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				title VARCHAR(255) NOT NULL,
				h1 VARCHAR(255) NULL,
				meta_title VARCHAR(255) NULL,
				meta_description TEXT NULL,
				slug VARCHAR(200) NULL,
				excerpt TEXT NULL,
				html_content LONGTEXT NOT NULL,
				faq_json LONGTEXT NULL,
				tags_json LONGTEXT NULL,
				internal_links_json LONGTEXT NULL,
				media_proposals_json LONGTEXT NULL,
				structured_json LONGTEXT NULL,
				qa_status VARCHAR(24) NOT NULL DEFAULT 'NOT_RUN',
				qa_result_json LONGTEXT NULL,
				approval_id BIGINT UNSIGNED NULL,
				status VARCHAR(24) NOT NULL DEFAULT 'DRAFT',
				created_at_utc DATETIME NOT NULL,
				updated_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_kw_draft (keyword_id),
				KEY idx_status (status),
				KEY idx_qa (qa_status)
			) {$charsetCollate};",

			'content_revisions' => "CREATE TABLE IF NOT EXISTS {$p}content_revisions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				draft_id BIGINT UNSIGNED NOT NULL,
				revision_no INT UNSIGNED NOT NULL,
				title VARCHAR(255) NOT NULL,
				html_content LONGTEXT NOT NULL,
				structured_json LONGTEXT NULL,
				change_summary VARCHAR(255) NULL,
				created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_draft_rev (draft_id, revision_no)
			) {$charsetCollate};",

			'rewrite_snapshots' => "CREATE TABLE IF NOT EXISTS {$p}rewrite_snapshots (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				draft_id BIGINT UNSIGNED NOT NULL,
				post_id BIGINT UNSIGNED NOT NULL,
				original_url VARCHAR(512) NULL,
				original_slug VARCHAR(200) NULL,
				original_title VARCHAR(255) NOT NULL,
				original_html LONGTEXT NOT NULL,
				original_meta_json LONGTEXT NULL,
				preserved_links_json LONGTEXT NULL,
				diff_summary_json LONGTEXT NULL,
				risk_level VARCHAR(20) NOT NULL DEFAULT 'LOW_RISK',
				created_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_draft_snap (draft_id),
				KEY idx_post_snap (post_id)
			) {$charsetCollate};",

			'media_proposals' => "CREATE TABLE IF NOT EXISTS {$p}media_proposals (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				draft_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				source_type VARCHAR(24) NOT NULL,
				attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				media_url VARCHAR(512) NULL,
				alt_text VARCHAR(255) NULL,
				caption TEXT NULL,
				image_title VARCHAR(255) NULL,
				placement VARCHAR(32) NOT NULL DEFAULT 'body',
				validation_status VARCHAR(24) NOT NULL DEFAULT 'PENDING',
				metadata_json LONGTEXT NULL,
				created_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_draft_media (draft_id)
			) {$charsetCollate};",
		];
	}

	/**
	 * @return array<string, string>
	 */
	public static function v050Tables(object $wpdb, string $charsetCollate = ''): array {
		$p = self::prefix($wpdb);
		return [
			'product_proposals' => "CREATE TABLE IF NOT EXISTS {$p}product_proposals (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				product_id BIGINT UNSIGNED NOT NULL,
				proposal_type VARCHAR(32) NOT NULL DEFAULT 'seo_update',
				proposed_fields_json LONGTEXT NOT NULL,
				snapshot_json LONGTEXT NOT NULL,
				state_hash VARCHAR(64) NOT NULL,
				diff_json LONGTEXT NULL,
				master_conflicts_json LONGTEXT NULL,
				approval_id BIGINT UNSIGNED NULL,
				status VARCHAR(24) NOT NULL DEFAULT 'DRAFT',
				created_at_utc DATETIME NOT NULL,
				updated_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_prod_id (product_id),
				KEY idx_status (status)
			) {$charsetCollate};",

			'publishing_receipts' => "CREATE TABLE IF NOT EXISTS {$p}publishing_receipts (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				idempotency_key VARCHAR(128) NOT NULL,
				approval_id BIGINT UNSIGNED NOT NULL,
				action_type VARCHAR(64) NOT NULL,
				target_type VARCHAR(32) NOT NULL,
				target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				wp_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				post_status VARCHAR(24) NOT NULL,
				execution_status VARCHAR(24) NOT NULL,
				receipt_json LONGTEXT NULL,
				error_message TEXT NULL,
				created_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_pub_idem (idempotency_key),
				KEY idx_approval (approval_id)
			) {$charsetCollate};",

			'link_index' => "CREATE TABLE IF NOT EXISTS {$p}link_index (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT UNSIGNED NOT NULL,
				url VARCHAR(512) NOT NULL,
				title VARCHAR(255) NOT NULL,
				post_type VARCHAR(32) NOT NULL DEFAULT 'post',
				category_slugs_json LONGTEXT NULL,
				outbound_links_json LONGTEXT NULL,
				updated_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_post_idx (post_id)
			) {$charsetCollate};",

			'link_proposals' => "CREATE TABLE IF NOT EXISTS {$p}link_proposals (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source_post_id BIGINT UNSIGNED NOT NULL,
				target_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				target_url VARCHAR(512) NOT NULL,
				anchor_text VARCHAR(255) NOT NULL,
				relevance_score DECIMAL(4,2) NOT NULL DEFAULT 0.00,
				placement VARCHAR(32) NOT NULL DEFAULT 'body',
				approval_id BIGINT UNSIGNED NULL,
				status VARCHAR(24) NOT NULL DEFAULT 'PROPOSED',
				created_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_src_post (source_post_id)
			) {$charsetCollate};",

			'master_products' => "CREATE TABLE IF NOT EXISTS {$p}master_products (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				sku VARCHAR(100) NOT NULL,
				model VARCHAR(120) NOT NULL,
				product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				price VARCHAR(64) NULL,
				specs_json LONGTEXT NOT NULL,
				warranty VARCHAR(120) NULL,
				gifts VARCHAR(255) NULL,
				product_url VARCHAR(512) NULL,
				source VARCHAR(120) NOT NULL DEFAULT 'MASTER_PRODUCT_HTD',
				last_verified_date VARCHAR(32) NULL,
				updated_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_master_sku (sku)
			) {$charsetCollate};",
		];
	}
}
