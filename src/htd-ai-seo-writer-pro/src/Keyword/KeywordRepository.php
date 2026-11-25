<?php
declare(strict_types=1);

namespace HTD\AISEO\Keyword;

use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Keyword queue repository with CRUD, search/filter, bulk actions, and CSV import/export.
 */
final class KeywordRepository {
	public function __construct(
		private readonly object $wpdb,
		private readonly Logger $logger
	) {}

	/**
	 * @param array<string, mixed> $data
	 */
	public function add(array $data): int {
		$keyword = trim((string) ($data['keyword'] ?? ''));
		if ($keyword === '') {
			throw new \InvalidArgumentException('Keyword cannot be empty.');
		}
		$lang = preg_replace('/[^a-zA-Z\-]/', '', (string) ($data['content_language'] ?? 'vi')) ?: 'vi';
		$table = Schema::table($this->wpdb, 'keywords');

		$existing = $this->findByKeyword($keyword, $lang);
		if ($existing !== null) {
			throw new \RuntimeException("Duplicate keyword '{$keyword}' ({$lang}).", 409);
		}

		$now = gmdate('Y-m-d H:i:s');
		$secondary = is_array($data['secondary_keywords'] ?? null)
			? array_values(array_filter(array_map('trim', $data['secondary_keywords'])))
			: [];

		$this->wpdb->insert($table, [
			'keyword'                 => $keyword,
			'secondary_keywords_json' => (string) json_encode($secondary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'keyword_group'           => isset($data['keyword_group']) ? trim((string) $data['keyword_group']) : null,
			'priority'                => max(1, min(10, (int) ($data['priority'] ?? 5))),
			'search_intent'           => (string) ($data['search_intent'] ?? 'UNKNOWN'),
			'intent_source'           => (string) ($data['intent_source'] ?? 'UNKNOWN'),
			'target_url'              => isset($data['target_url']) ? trim((string) $data['target_url']) : null,
			'target_post_type'        => (string) ($data['target_post_type'] ?? 'post'),
			'target_category_id'      => max(0, (int) ($data['target_category_id'] ?? 0)),
			'assigned_provider'       => (string) ($data['assigned_provider'] ?? 'openai'),
			'assigned_model'          => isset($data['assigned_model']) ? (string) $data['assigned_model'] : null,
			'assigned_prompt'         => (string) ($data['assigned_prompt'] ?? 'seo_article'),
			'content_language'        => $lang,
			'status'                  => 'pending',
			'processing_history_json' => (string) json_encode([['at' => $now, 'event' => 'created']], JSON_UNESCAPED_UNICODE),
			'created_at_utc'          => $now,
			'updated_at_utc'          => $now,
		]);
		$id = (int) $this->wpdb->insert_id;
		$this->logger->log('keywords', 'keyword_added', 'AUDIT', 'OK', ['keyword_id' => $id, 'keyword' => $keyword]);
		return $id;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array{added: int, skipped: int}
	 */
	public function addBatch(array $items): array {
		$added = 0;
		$skipped = 0;
		foreach ($items as $item) {
			try {
				$this->add($item);
				$added++;
			} catch (\Throwable) {
				$skipped++;
			}
		}
		return ['added' => $added, 'skipped' => $skipped];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): bool {
		$row = $this->find($id);
		if ($row === null) {
			return false;
		}
		$allowed = ['keyword_group', 'priority', 'search_intent', 'intent_source', 'target_url', 'target_post_type', 'target_category_id', 'assigned_provider', 'assigned_model', 'assigned_prompt', 'status', 'error_message'];
		$upd = ['updated_at_utc' => gmdate('Y-m-d H:i:s')];
		foreach ($allowed as $col) {
			if (array_key_exists($col, $data)) {
				$upd[$col] = $data[$col];
			}
		}
		if (isset($data['secondary_keywords']) && is_array($data['secondary_keywords'])) {
			$upd['secondary_keywords_json'] = (string) json_encode(array_values($data['secondary_keywords']), JSON_UNESCAPED_UNICODE);
		}
		$table = Schema::table($this->wpdb, 'keywords');
		$this->wpdb->update($table, $upd, ['id' => $id]);
		return true;
	}

	public function delete(int $id): bool {
		$table = Schema::table($this->wpdb, 'keywords');
		$res = $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$table} WHERE id = %d", $id));
		if ($res) {
			$this->logger->log('keywords', 'keyword_deleted', 'AUDIT', 'OK', ['keyword_id' => $id]);
			return true;
		}
		return false;
	}

	/**
	 * @param int[] $ids
	 */
	public function bulkAction(array $ids, string $action): int {
		$count = 0;
		foreach (array_map('intval', $ids) as $id) {
			if ($id <= 0) {
				continue;
			}
			$ok = match ($action) {
				'pause'  => $this->update($id, ['status' => 'pause']),
				'resume' => $this->update($id, ['status' => 'pending']),
				'delete' => $this->delete($id),
				default  => false,
			};
			if ($ok) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(int $id): ?array {
		$table = Schema::table($this->wpdb, 'keywords');
		$row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function findByKeyword(string $keyword, string $lang = 'vi'): ?array {
		$table = Schema::table($this->wpdb, 'keywords');
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare("SELECT * FROM {$table} WHERE keyword = %s AND content_language = %s LIMIT 1", $keyword, $lang),
			ARRAY_A
		);
		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list(int $limit = 50, int $offset = 0, ?string $status = null, ?string $search = null): array {
		$table = Schema::table($this->wpdb, 'keywords');
		$where = [];
		$params = [];
		if ($status !== null && $status !== '') {
			$where[] = 'status = %s';
			$params[] = $status;
		}
		if ($search !== null && $search !== '') {
			$where[] = 'keyword LIKE %s';
			$params[] = '%' . $search . '%';
		}
		$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
		$params[] = max(1, min(200, $limit));
		$params[] = max(0, $offset);

		$sql = $this->wpdb->prepare("SELECT * FROM {$table} {$whereSql} ORDER BY priority ASC, id DESC LIMIT %d OFFSET %d", ...$params);
		$rows = $this->wpdb->get_results($sql, ARRAY_A);
		return is_array($rows) ? $rows : [];
	}

	/**
	 * Import CSV lines: keyword,secondary_keywords(|),keyword_group,priority,target_url
	 *
	 * @return array{added: int, skipped: int}
	 */
	public function importCsv(string $csvContent): array {
		$lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];
		$items = [];
		foreach ($lines as $idx => $line) {
			$cols = str_getcsv($line);
			if ($idx === 0 && isset($cols[0]) && strtolower(trim($cols[0])) === 'keyword') {
				continue;
			}
			$kw = trim((string) ($cols[0] ?? ''));
			if ($kw === '') {
				continue;
			}
			$sec = isset($cols[1]) && $cols[1] !== '' ? explode('|', (string) $cols[1]) : [];
			$items[] = [
				'keyword'            => $kw,
				'secondary_keywords' => $sec,
				'keyword_group'      => isset($cols[2]) ? trim((string) $cols[2]) : null,
				'priority'           => isset($cols[3]) ? (int) $cols[3] : 5,
				'target_url'         => isset($cols[4]) ? trim((string) $cols[4]) : null,
			];
		}
		return $this->addBatch($items);
	}

	public function exportCsv(?string $status = null): string {
		$rows = $this->list(200, 0, $status);
		$fh = fopen('php://temp', 'r+');
		if ($fh === false) {
			return '';
		}
		fputcsv($fh, ['id', 'keyword', 'secondary_keywords', 'keyword_group', 'priority', 'search_intent', 'target_url', 'status']);
		foreach ($rows as $r) {
			$sec = json_decode((string) ($r['secondary_keywords_json'] ?? '[]'), true);
			$kw = self::escapeCsvFormula((string) $r['keyword']);
			fputcsv($fh, [
				$r['id'],
				$kw,
				is_array($sec) ? implode('|', $sec) : '',
				self::escapeCsvFormula((string) ($r['keyword_group'] ?? '')),
				$r['priority'],
				$r['search_intent'],
				(string) ($r['target_url'] ?? ''),
				$r['status'],
			]);
		}
		rewind($fh);
		$out = (string) stream_get_contents($fh);
		fclose($fh);
		return $out;
	}

	private static function escapeCsvFormula(string $val): string {
		if ($val !== '' && in_array($val[0], ['=', '+', '-', '@'], true)) {
			return "'" . $val;
		}
		return $val;
	}
}
