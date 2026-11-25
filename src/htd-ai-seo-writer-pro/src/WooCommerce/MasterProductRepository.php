<?php
declare(strict_types=1);

namespace HTD\AISEO\WooCommerce;

use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * MASTER PRODUCT HTD verified specification repository (CSV/JSON import & conflict detection).
 */
final class MasterProductRepository {
	public function __construct(
		private readonly object $wpdb,
		private readonly Logger $logger
	) {}

	/**
	 * @param array<string, mixed> $record
	 */
	public function upsert(array $record): int {
		$sku = trim((string) ($record['sku'] ?? ''));
		$model = trim((string) ($record['model'] ?? ''));
		if ($sku === '' || $model === '') {
			throw new \InvalidArgumentException('MASTER PRODUCT requires both sku and model.');
		}
		$table = Schema::table($this->wpdb, 'master_products');
		$now = gmdate('Y-m-d H:i:s');
		$specs = is_array($record['specs'] ?? null) ? $record['specs'] : [
			'cpu' => $record['cpu'] ?? '',
			'ram' => $record['ram'] ?? '',
			'rom' => $record['rom'] ?? '',
			'technical_specifications' => $record['technical_specifications'] ?? '',
			'features' => $record['features'] ?? '',
		];

		$existing = $this->findBySku($sku);
		$data = [
			'sku'                => $sku,
			'model'              => $model,
			'product_id'         => (int) ($record['product_id'] ?? 0),
			'price'              => isset($record['price']) ? (string) $record['price'] : null,
			'specs_json'         => (string) json_encode($specs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'warranty'           => isset($record['warranty']) ? (string) $record['warranty'] : null,
			'gifts'              => isset($record['gifts']) ? (string) $record['gifts'] : null,
			'product_url'        => isset($record['product_url']) ? (string) $record['product_url'] : null,
			'source'             => (string) ($record['source'] ?? 'MASTER_PRODUCT_HTD'),
			'last_verified_date' => isset($record['last_verified_date']) ? (string) $record['last_verified_date'] : gmdate('Y-m-d'),
			'updated_at_utc'     => $now,
		];

		if ($existing !== null) {
			$this->wpdb->update($table, $data, ['sku' => $sku]);
			return (int) $existing['id'];
		}
		$this->wpdb->insert($table, $data);
		$id = (int) $this->wpdb->insert_id;
		$this->logger->log('master_product', 'upserted', 'AUDIT', 'OK', ['sku' => $sku, 'model' => $model]);
		return $id;
	}

	/**
	 * @return array{imported: int, skipped: int}
	 */
	public function importJson(string $json): array {
		$items = json_decode($json, true);
		if (!is_array($items)) {
			throw new \InvalidArgumentException('Invalid MASTER PRODUCT JSON.');
		}
		$imp = 0;
		$skip = 0;
		foreach ($items as $item) {
			if (!is_array($item)) {
				$skip++;
				continue;
			}
			try {
				$this->upsert($item);
				$imp++;
			} catch (\Throwable) {
				$skip++;
			}
		}
		return ['imported' => $imp, 'skipped' => $skip];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function findBySku(string $sku): ?array {
		$table = Schema::table($this->wpdb, 'master_products');
		$row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$table} WHERE sku = %s LIMIT 1", $sku), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	/**
	 * Compare WooCommerce product against MASTER PRODUCT HTD without auto-overwriting.
	 *
	 * @param array<string, mixed> $wcProduct
	 * @return array<int, array{field: string, master: string, woocommerce: string, status: string}>
	 */
	public function detectConflicts(array $wcProduct): array {
		$sku = trim((string) ($wcProduct['sku'] ?? ''));
		if ($sku === '') {
			return [['field' => 'sku', 'master' => 'UNKNOWN', 'woocommerce' => '', 'status' => 'NEEDS_VERIFICATION']];
		}
		$master = $this->findBySku($sku);
		if ($master === null) {
			return [['field' => 'master_record', 'master' => 'NOT_FOUND', 'woocommerce' => $sku, 'status' => 'NEEDS_VERIFICATION']];
		}

		$conflicts = [];
		if ($master['price'] !== null && isset($wcProduct['regular_price']) && (string) $master['price'] !== (string) $wcProduct['regular_price']) {
			$conflicts[] = [
				'field'       => 'price',
				'master'      => (string) $master['price'],
				'woocommerce' => (string) $wcProduct['regular_price'],
				'status'      => 'NEEDS_VERIFICATION',
			];
		}
		return $conflicts;
	}
}
