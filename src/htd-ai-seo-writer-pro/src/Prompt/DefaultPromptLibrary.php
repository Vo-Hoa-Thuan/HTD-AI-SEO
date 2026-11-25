<?php
declare(strict_types=1);

namespace HTD\AISEO\Prompt;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Built-in 20 HTD-authored SEO prompt templates (Vietnamese-first, anti-hallucination).
 */
final class DefaultPromptLibrary {
	private const ANTI_HALLUCINATION = 'Quy tắc bắt buộc: KHÔNG bịa thông số kỹ thuật, giá bán, URL, đánh giá khách hàng hay nguồn tham khảo. KHÔNG cam kết thứ hạng Google. Nếu thiếu dữ liệu sản phẩm/nguồn xác thực, trả về giá trị "NEEDS_VERIFICATION". Chỉ trả về JSON hợp lệ.';

	/**
	 * @return array<string, PromptTemplate>
	 */
	public static function all(): array {
		$defs = [
			['keyword_analysis', 'Phân tích từ khóa SEO', 'keyword_analysis', ['keyword'], ['secondary_keywords'], ['primary_keyword' => 'string', 'search_intent' => 'string', 'subtopics' => 'array', 'verification_status' => 'string']],
			['keyword_clustering', 'Phân cụm từ khóa theo chủ đề', 'keyword_clustering', ['keywords_list'], ['target_category'], ['clusters' => 'array']],
			['intent_classification', 'Phân loại Search Intent', 'intent_classification', ['keyword'], ['target_url'], ['intent' => 'string', 'confidence' => 'number', 'recommended_content_type' => 'string']],
			['content_outline', 'Lập dàn ý bài viết SEO', 'content_outline', ['keyword'], ['secondary_keywords', 'heading_depth'], ['title' => 'string', 'headings' => 'array', 'faq_topics' => 'array']],
			['seo_article', 'Viết bài chuẩn SEO HTD', 'seo_article', ['keyword', 'outline'], ['secondary_keywords', 'word_target'], ['title' => 'string', 'html_content' => 'string', 'faq' => 'array', 'verification_flags' => 'array']],
			['article_rewrite', 'Viết lại & tối ưu bài viết cũ', 'article_rewrite', ['keyword', 'existing_content'], ['target_url'], ['title' => 'string', 'html_content' => 'string', 'changes_summary' => 'string']],
			['meta_title', 'Tạo SEO Meta Title', 'meta_title', ['keyword', 'topic'], [], ['meta_title' => 'string', 'char_count' => 'int']],
			['meta_description', 'Tạo SEO Meta Description', 'meta_description', ['keyword', 'summary'], [], ['meta_description' => 'string', 'char_count' => 'int']],
			['faq_generation', 'Tạo câu hỏi thường gặp (FAQ)', 'faq_generation', ['keyword'], ['product_context'], ['faqs' => 'array']],
			['product_description', 'Mô tả chi tiết sản phẩm HTD', 'product_description', ['product_name', 'verified_specs'], ['category_url'], ['html_description' => 'string', 'missing_data' => 'array']],
			['product_short_desc', 'Mô tả ngắn sản phẩm WooCommerce', 'product_short_desc', ['product_name', 'verified_specs'], [], ['short_description' => 'string']],
			['internal_links', 'Đề xuất liên kết nội bộ', 'internal_links', ['article_topic', 'candidate_urls'], [], ['recommendations' => 'array']],
			['image_prompt', 'Tạo prompt minh họa bài viết', 'image_prompt', ['topic'], ['style'], ['image_prompt' => 'string']],
			['image_alt', 'Tạo văn bản thay thế (ALT Text)', 'image_alt', ['keyword', 'image_context'], [], ['alt_text' => 'string']],
			['content_quality_review', 'Đánh giá chất lượng nội dung', 'content_quality_review', ['html_content', 'keyword'], [], ['score' => 'int', 'issues' => 'array', 'needs_verification' => 'array']],
			['seo_quality_review', 'Đánh giá chuẩn SEO Onpage/Rank Math', 'seo_quality_review', ['title', 'meta_description', 'html_content', 'keyword'], [], ['seo_score_estimate' => 'int', 'recommendations' => 'array']],
			['product_comparison', 'So sánh sản phẩm ô tô HTD', 'product_comparison', ['products_verified_data'], [], ['comparison_html' => 'string', 'missing_fields' => 'array']],
			['installation_case_study', 'Case study lắp đặt thực tế', 'installation_case_study', ['vehicle_model', 'product_name', 'verified_notes'], [], ['title' => 'string', 'html_content' => 'string']],
			['category_intro', 'Giới thiệu danh mục sản phẩm SEO', 'category_intro', ['category_name', 'category_url'], ['verified_products'], ['intro_html' => 'string']],
			['content_refresh', 'Đề xuất làm mới nội dung (Freshness)', 'content_refresh', ['existing_content', 'gsc_metrics'], [], ['refresh_priority' => 'string', 'recommended_updates' => 'array']],
		];

		$out = [];
		foreach ($defs as [$id, $name, $task, $req, $opt, $schema]) {
			$placeholders = implode("\n", array_map(static fn(string $v): string => "- {$v}: {{" . $v . "}}", array_merge($req, $opt)));
			$out[$id] = new PromptTemplate(
				id: $id,
				name: $name,
				taskType: $task,
				version: '1.0.0',
				language: 'vi',
				systemInstructions: "Bạn là chuyên gia SEO & kỹ thuật nội dung của HTD Việt Nam.\n" . self::ANTI_HALLUCINATION,
				userTemplate: "Thực hiện nhiệm vụ: {$name}.\nDữ liệu đầu vào:\n{$placeholders}",
				requiredVariables: $req,
				optionalVariables: $opt,
				outputSchema: $schema,
				tokenBudget: 2048,
				status: 'active',
				createdAt: '2026-11-25 00:00:00',
				updatedAt: '2026-11-25 00:00:00'
			);
		}
		return $out;
	}
}
