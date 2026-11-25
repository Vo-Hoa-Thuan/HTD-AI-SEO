<?php
declare(strict_types=1);

namespace HTD\AISEO\WooCommerce;

use HTD\AISEO\Support\Environment;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Safe WooCommerce CRUD adapter (gracefully handles missing WooCommerce without fatal errors).
 */
final class WooCommerceAdapter {
	public function isAvailable(): bool {
		return Environment::isWooCommerceActive() && function_exists('wc_get_product');
	}

	/**
	 * @param array<int, array<string, mixed>> $fixtureProducts
	 * @return array<int, array<string, mixed>>
	 */
	public function listProducts(int $limit = 20, string $category = '', string $status = 'publish', array $fixtureProducts = []): array {
		if (!$this->isAvailable()) {
			$out = [];
			foreach ($fixtureProducts as $p) {
				if ($category !== '' && !in_array($category, (array) ($p['categories'] ?? []), true)) {
					continue;
				}
				if ($status !== '' && ($p['status'] ?? 'publish') !== $status) {
					continue;
				}
				$out[] = $p;
				if (count($out) >= $limit) {
					break;
				}
			}
			return $out;
		}

		$args = ['limit' => max(1, min(100, $limit)), 'status' => $status];
		if ($category !== '') {
			$args['category'] = [$category];
		}
		$products = wc_get_products($args);
		$out = [];
		foreach ($products as $prod) {
			$out[] = $this->normalizeProduct($prod);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed>|null $fixtureProduct
	 * @return array<string, mixed>|null
	 */
	public function getProduct(int $productId, ?array $fixtureProduct = null): ?array {
		if (!$this->isAvailable()) {
			return $fixtureProduct;
		}
		$prod = wc_get_product($productId);
		return $prod ? $this->normalizeProduct($prod) : null;
	}

	/**
	 * Update ONLY approved description/short_description fields via official WC CRUD.
	 * Never modifies price, SKU, stock, attributes, categories, or slug.
	 *
	 * @param array<string, mixed> $approvedFields
	 */
	public function updateDescriptionFields(int $productId, array $approvedFields): bool {
		if (!$this->isAvailable()) {
			return false;
		}
		$prod = wc_get_product($productId);
		if (!$prod) {
			return false;
		}
		if (isset($approvedFields['description'])) {
			$prod->set_description((string) $approvedFields['description']);
		}
		if (isset($approvedFields['short_description'])) {
			$prod->set_short_description((string) $approvedFields['short_description']);
		}
		return (int) $prod->save() > 0;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function normalizeProduct(object $prod): array {
		return [
			'id'                => $prod->get_id(),
			'name'              => $prod->get_name(),
			'sku'               => $prod->get_sku(),
			'type'              => $prod->get_type(),
			'slug'              => $prod->get_slug(),
			'url'               => get_permalink($prod->get_id()),
			'regular_price'     => $prod->get_regular_price(),
			'sale_price'        => $prod->get_sale_price(),
			'stock_quantity'    => $prod->get_stock_quantity(),
			'stock_status'      => $prod->get_stock_status(),
			'description'       => $prod->get_description(),
			'short_description' => $prod->get_short_description(),
			'categories'        => wp_get_post_terms($prod->get_id(), 'product_cat', ['fields' => 'slugs']),
			'attributes'        => array_keys((array) $prod->get_attributes()),
			'image_id'          => $prod->get_image_id(),
			'status'            => $prod->get_status(),
		];
	}
}
