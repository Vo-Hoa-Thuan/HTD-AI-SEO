<?php
declare(strict_types=1);

namespace HTD\AISEO\Brand;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Configurable Brand Profile (defaults to HTD Vietnam, adaptable to any site).
 */
final class BrandProfile {
	public const OPTION_NAME = 'htd_ai_seo_brand_profile';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'brand_name'          => 'HTD Việt Nam',
			'website'             => 'https://htd.net.vn',
			'industry'            => 'Thiết bị và phụ kiện ô tô',
			'primary_category'    => 'Camera hành trình ô tô',
			'secondary_category'  => 'Android Box ô tô',
			'priority_urls'       => [
				'https://htd.net.vn/danh-muc-san-pham/camera-hanh-trinh-o-to/',
				'https://htd.net.vn/danh-muc-san-pham/android-box-o-to/',
			],
			'tone_of_voice'       => 'Chuyên nghiệp, kỹ thuật chuẩn xác, dễ hiểu, minh bạch.',
			'target_audience'     => 'Chủ xe ô tô tại Việt Nam tìm kiếm giải pháp nâng cấp an toàn & giải trí.',
			'approved_terms'      => ['Camera hành trình ô tô', 'Android Box ô tô', 'HTD Việt Nam'],
			'forbidden_claims'    => ['Cam kết Top 1 Google', 'Rẻ nhất thị trường', 'Bảo hành vĩnh viễn không điều kiện'],
			'product_data_policy' => 'Chỉ sử dụng thông số đã xác thực từ MASTER PRODUCT HTD. Không tự suy diễn.',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$stored = function_exists('get_option') ? get_option(self::OPTION_NAME, []) : [];
		if (!is_array($stored)) {
			$stored = [];
		}
		return array_merge(self::defaults(), $stored);
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function save(array $input): void {
		$current = $this->get();
		foreach (['brand_name', 'website', 'industry', 'primary_category', 'secondary_category', 'tone_of_voice', 'target_audience', 'product_data_policy'] as $field) {
			if (isset($input[$field])) {
				$current[$field] = trim(strip_tags((string) $input[$field]));
			}
		}
		foreach (['priority_urls', 'approved_terms', 'forbidden_claims'] as $arrField) {
			if (isset($input[$arrField])) {
				$items = is_array($input[$arrField]) ? $input[$arrField] : explode("\n", (string) $input[$arrField]);
				$current[$arrField] = array_values(array_filter(array_map(static fn($v): string => trim(strip_tags((string) $v)), $items)));
			}
		}
		if (function_exists('update_option')) {
			update_option(self::OPTION_NAME, $current, false);
		}
	}

	public function toPromptContext(): string {
		$p = $this->get();
		return "Brand Profile: {$p['brand_name']} ({$p['website']}) | Ngành: {$p['industry']} | "
			. "Danh mục chính: {$p['primary_category']}, {$p['secondary_category']} | "
			. "Giọng văn: {$p['tone_of_voice']} | Chính sách dữ liệu: {$p['product_data_policy']} | "
			. "Cấm khẳng định: " . implode('; ', (array) $p['forbidden_claims']);
	}
}
