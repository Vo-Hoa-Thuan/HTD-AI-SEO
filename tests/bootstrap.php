<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('HTD_AI_SEO_VERSION', '0.9.1-dev');
define('ABSPATH_VER_CHECK', true);
define('HTD_AI_SEO_DB_VERSION', '0.6.0');
define('HTD_AI_SEO_PATH', dirname(__DIR__) . '/src/htd-ai-seo-writer-pro/');
define('HTD_AI_SEO_URL', 'http://localhost/wp-content/plugins/htd-ai-seo-writer-pro/');
define('HTD_AI_SEO_BASENAME', 'htd-ai-seo-writer-pro/htd-ai-seo-writer-pro.php');

spl_autoload_register(static function (string $class): void {
	$prefix = 'HTD\\AISEO\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}
	$relative = substr($class, strlen($prefix));
	$file = HTD_AI_SEO_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});

$GLOBALS['htd_test_options'] = [];

function get_option(string $option, mixed $default = false): mixed {
	return $GLOBALS['htd_test_options'][$option] ?? $default;
}

function add_option(string $option, mixed $value = '', string $deprecated = '', string|bool $autoload = 'yes'): bool {
	if (array_key_exists($option, $GLOBALS['htd_test_options'])) {
		return false;
	}
	$GLOBALS['htd_test_options'][$option] = $value;
	return true;
}

function update_option(string $option, mixed $value, string|bool|null $autoload = null): bool {
	$GLOBALS['htd_test_options'][$option] = $value;
	return true;
}

function delete_option(string $option): bool {
	unset($GLOBALS['htd_test_options'][$option]);
	return true;
}

function wp_salt(string $scheme = 'auth'): string {
	return "test-salt-{$scheme}-htd-ai-seo-writer-pro-2026";
}

function wp_kses_post(string $data): string {
	return strip_tags($data, '<p><h1><h2><h3><h4><ul><ol><li><strong><em><a><img><table><thead><tbody><tr><th><td><blockquote><code><pre><br>');
}

/**
 * Pure-PHP in-memory $wpdb test double (no PDO/SQLite extension required).
 */
final class TestWpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;

	/** @var array<string, array<int, array<string, mixed>>> */
	public array $tables = [];
	/** @var array<string, int> */
	private array $autoInc = [];

	public function __construct(string $prefix = 'wp_') {
		$this->prefix = $prefix;
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function prepare(string $query, mixed ...$args): string {
		$idx = 0;
		return (string) preg_replace_callback('/%[sdf]/', static function (array $m) use (&$idx, $args): string {
			$val = $args[$idx++] ?? '';
			if ($m[0] === '%d') {
				return (string) (int) $val;
			}
			if ($m[0] === '%f') {
				return (string) (float) $val;
			}
			if ($val === null) {
				return 'NULL';
			}
			return "'" . str_replace("'", "\\'", (string) $val) . "'";
		}, $query);
	}

	public function query(string $sql): int|bool {
		$s = trim($sql);
		if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-zA-Z0-9_]+)/i', $s, $m)) {
			$tbl = $m[1];
			if (!isset($this->tables[$tbl])) {
				$this->tables[$tbl] = [];
				$this->autoInc[$tbl] = 0;
			}
			return 1;
		}
		if (preg_match('/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?([a-zA-Z0-9_]+)/i', $s, $m)) {
			unset($this->tables[$m[1]], $this->autoInc[$m[1]]);
			return 1;
		}
		// Approval claim: UPDATE ... SET status = 'EXECUTING' WHERE id = X AND status = 'APPROVED'
		if (preg_match('/^UPDATE\s+([a-zA-Z0-9_]+approvals)\s+SET\s+status\s*=\s*\'([^\']+)\'\s+WHERE\s+id\s*=\s*(\d+)\s+AND\s+status\s*=\s*\'([^\']+)\'/i', $s, $m)) {
			[$all, $tbl, $newSt, $id, $reqSt] = $m;
			$id = (int) $id;
			if (isset($this->tables[$tbl][$id]) && $this->tables[$tbl][$id]['status'] === $reqSt) {
				$this->tables[$tbl][$id]['status'] = $newSt;
				return 1;
			}
			return 0;
		}
		// Queue claim: UPDATE ... SET status = 'RUNNING', lock_owner = '...', lease_expires_at = '...', heartbeat_at = '...', attempts = attempts + 1, updated_at_utc = '...' WHERE id = X AND status IN ('PENDING', 'FAILED')
		if (preg_match('/^UPDATE\s+([a-zA-Z0-9_]+queue)\s+SET\s+status\s*=\s*\'([^\']+)\',\s*lock_owner\s*=\s*\'([^\']+)\',\s*lease_expires_at\s*=\s*\'([^\']+)\',\s*heartbeat_at\s*=\s*\'([^\']+)\',\s*attempts\s*=\s*attempts\s*\+\s*1,\s*updated_at_utc\s*=\s*\'([^\']+)\'\s+WHERE\s+id\s*=\s*(\d+)/i', $s, $m)) {
			$tbl = $m[1];
			$id = (int) $m[7];
			if (isset($this->tables[$tbl][$id]) && in_array($this->tables[$tbl][$id]['status'], ['PENDING', 'FAILED'], true)) {
				$this->tables[$tbl][$id]['status'] = $m[2];
				$this->tables[$tbl][$id]['lock_owner'] = $m[3];
				$this->tables[$tbl][$id]['lease_expires_at'] = $m[4];
				$this->tables[$tbl][$id]['heartbeat_at'] = $m[5];
				$this->tables[$tbl][$id]['attempts'] = ((int) $this->tables[$tbl][$id]['attempts']) + 1;
				$this->tables[$tbl][$id]['updated_at_utc'] = $m[6];
				return 1;
			}
			return 0;
		}
		// Queue heartbeat
		if (preg_match('/^UPDATE\s+([a-zA-Z0-9_]+queue)\s+SET\s+heartbeat_at\s*=\s*\'([^\']+)\',\s*lease_expires_at\s*=\s*\'([^\']+)\',\s*updated_at_utc\s*=\s*\'([^\']+)\'\s+WHERE\s+id\s*=\s*(\d+)\s+AND\s+status\s*=\s*\'([^\']+)\'\s+AND\s+lock_owner\s*=\s*\'([^\']+)\'\s+AND\s+lease_expires_at\s*>=\s*\'([^\']+)\'/i', $s, $m)) {
			$tbl = $m[1];
			$id = (int) $m[5];
			if (isset($this->tables[$tbl][$id]) && $this->tables[$tbl][$id]['status'] === $m[6] && $this->tables[$tbl][$id]['lock_owner'] === $m[7] && !empty($this->tables[$tbl][$id]['lease_expires_at']) && $this->tables[$tbl][$id]['lease_expires_at'] >= $m[8]) {
				$this->tables[$tbl][$id]['heartbeat_at'] = $m[2];
				$this->tables[$tbl][$id]['lease_expires_at'] = $m[3];
				$this->tables[$tbl][$id]['updated_at_utc'] = $m[4];
				return 1;
			}
			return 0;
		}
		// Queue expired lease recovery
		if (str_contains($s, 'Lease expired before completion')) {
			$now = gmdate('Y-m-d H:i:s');
			$cnt = 0;
			foreach ($this->tables as $tbl => &$rows) {
				if (!str_ends_with($tbl, 'queue')) continue;
				foreach ($rows as &$r) {
					if ($r['status'] === 'RUNNING' && !empty($r['lease_expires_at']) && $r['lease_expires_at'] < $now) {
						$r['status'] = ((int) $r['attempts'] >= (int) $r['max_attempts']) ? 'DEAD_LETTER' : 'FAILED';
						$r['lock_owner'] = null;
						$r['lease_expires_at'] = null;
						$cnt++;
					}
				}
			}
			return $cnt;
		}
		// job_attempts finish
		if (preg_match('/^UPDATE\s+([a-zA-Z0-9_]+job_attempts)\s+SET\s+status\s*=\s*\'([^\']+)\'/i', $s, $m)) {
			return 1;
		}
		// Queue complete
		if (preg_match('/^UPDATE\s+([a-zA-Z0-9_]+queue)\s+SET\s+status\s*=\s*\'COMPLETED\',\s*lock_owner\s*=\s*NULL,\s*lease_expires_at\s*=\s*NULL,\s*updated_at_utc\s*=\s*\'([^\']+)\'\s+WHERE\s+id\s*=\s*(\d+)\s+AND\s+status\s*=\s*\'RUNNING\'\s+AND\s+lock_owner\s*=\s*\'([^\']+)\'\s+AND\s+lease_expires_at\s*>=\s*\'([^\']+)\'/i', $s, $m)) {
			$tbl = $m[1];
			$id = (int) $m[3];
			if (isset($this->tables[$tbl][$id]) && $this->tables[$tbl][$id]['status'] === 'RUNNING' && $this->tables[$tbl][$id]['lock_owner'] === $m[4] && !empty($this->tables[$tbl][$id]['lease_expires_at']) && $this->tables[$tbl][$id]['lease_expires_at'] >= $m[5]) {
				$this->tables[$tbl][$id]['status'] = 'COMPLETED';
				$this->tables[$tbl][$id]['lock_owner'] = null;
				$this->tables[$tbl][$id]['lease_expires_at'] = null;
				$this->tables[$tbl][$id]['updated_at_utc'] = $m[2];
				return 1;
			}
			return 0;
		}
		// Queue cancel
		if (preg_match('/^UPDATE\s+([a-zA-Z0-9_]+queue)\s+SET\s+status\s*=\s*\'CANCELLED\'.*WHERE\s+id\s*=\s*(\d+)/i', $s, $m)) {
			$tbl = $m[1];
			$id = (int) $m[2];
			if (isset($this->tables[$tbl][$id]) && in_array($this->tables[$tbl][$id]['status'], ['PENDING', 'FAILED', 'RUNNING'], true)) {
				$this->tables[$tbl][$id]['status'] = 'CANCELLED';
				$this->tables[$tbl][$id]['lock_owner'] = null;
				$this->tables[$tbl][$id]['lease_expires_at'] = null;
				return 1;
			}
			return 0;
		}
		// Generic DELETE FROM table WHERE id = X
		if (preg_match('/^DELETE\s+FROM\s+([a-zA-Z0-9_]+)\s+WHERE\s+id\s*=\s*(\d+)/i', $s, $m)) {
			if (isset($this->tables[$m[1]][(int) $m[2]])) {
				unset($this->tables[$m[1]][(int) $m[2]]);
				return 1;
			}
			return 0;
		}
		return 0;
	}

	public function insert(string $table, array $data): int|false {
		if (!isset($this->tables[$table])) {
			$this->tables[$table] = [];
			$this->autoInc[$table] = 0;
		}
		$id = ++$this->autoInc[$table];
		if (!isset($data['id'])) {
			$data['id'] = $id;
		}
		$this->tables[$table][$id] = $data;
		$this->insert_id = $id;
		return 1;
	}

	public function update(string $table, array $data, array $where): int|false {
		if (!isset($this->tables[$table])) {
			return 0;
		}
		$updated = 0;
		foreach ($this->tables[$table] as &$row) {
			$match = true;
			foreach ($where as $wk => $wv) {
				if (($row[$wk] ?? null) != $wv) {
					$match = false;
					break;
				}
			}
			if ($match) {
				foreach ($data as $dk => $dv) {
					$row[$dk] = $dv;
				}
				$updated++;
			}
		}
		return $updated;
	}

	public function get_var(string $sql): mixed {
		if (preg_match('/SELECT\s+version\s+FROM\s+([a-zA-Z0-9_]+)\s+WHERE\s+version\s*=\s*\'([^\']+)\'/i', $sql, $m)) {
			foreach ($this->tables[$m[1]] ?? [] as $r) {
				if (($r['version'] ?? '') === $m[2]) return $r['version'];
			}
			return null;
		}
		if (preg_match('/SELECT\s+id\s+FROM\s+([a-zA-Z0-9_]+)\s+WHERE\s+idempotency_key\s*=\s*\'([^\']+)\'/i', $sql, $m)) {
			foreach ($this->tables[$m[1]] ?? [] as $r) {
				if (($r['idempotency_key'] ?? '') === $m[2]) return $r['id'];
			}
			return null;
		}
		if (preg_match('/SELECT\s+id\s+FROM\s+([a-zA-Z0-9_]+analytics_snapshots)\s+WHERE\s+source\s*=\s*\'([^\']+)\'.*dataset_hash\s*=\s*\'([^\']+)\'/i', $sql, $m)) {
			foreach ($this->tables[$m[1]] ?? [] as $r) {
				if (($r['source'] ?? '') === $m[2] && ($r['dataset_hash'] ?? '') === $m[3]) return $r['id'];
			}
			return null;
		}
		return null;
	}

	public function get_row(string $sql, string $output = ARRAY_A): ?array {
		if (preg_match('/FROM\s+([a-zA-Z0-9_]+)\s+WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
			return $this->tables[$m[1]][(int) $m[2]] ?? null;
		}
		if (preg_match('/FROM\s+([a-zA-Z0-9_]+)\s+WHERE\s+version\s*=\s*\'([^\']+)\'/i', $sql, $m)) {
			foreach ($this->tables[$m[1]] ?? [] as $r) {
				if (($r['version'] ?? '') === $m[2]) return $r;
			}
			return null;
		}
		if (preg_match('/FROM\s+([a-zA-Z0-9_]+queue)\s+WHERE\s+status\s+IN/i', $sql, $m)) {
			foreach ($this->tables[$m[1]] ?? [] as $r) {
				if (in_array($r['status'], ['PENDING', 'FAILED'], true)) return $r;
			}
			return null;
		}
		if (preg_match('/FROM\s+([a-zA-Z0-9_]+keywords)\s+WHERE\s+keyword\s*=\s*\'([^\']+)\'\s+AND\s+content_language\s*=\s*\'([^\']+)\'/i', $sql, $m)) {
			foreach ($this->tables[$m[1]] ?? [] as $r) {
				if (($r['keyword'] ?? '') === $m[2] && ($r['content_language'] ?? '') === $m[3]) return $r;
			}
			return null;
		}
		if (preg_match('/FROM\s+([a-zA-Z0-9_]+master_products)\s+WHERE\s+sku\s*=\s*\'([^\']+)\'/i', $sql, $m)) {
			foreach ($this->tables[$m[1]] ?? [] as $r) {
				if (($r['sku'] ?? '') === $m[2]) return $r;
			}
			return null;
		}
		if (preg_match('/FROM\s+([a-zA-Z0-9_]+publishing_receipts)\s+WHERE\s+idempotency_key\s*=\s*\'([^\']+)\'/i', $sql, $m)) {
			foreach ($this->tables[$m[1]] ?? [] as $r) {
				if (($r['idempotency_key'] ?? '') === $m[2]) return $r;
			}
			return null;
		}
		if (preg_match('/FROM\s+([a-zA-Z0-9_]+link_index)\s+WHERE\s+post_id\s*=\s*(\d+)/i', $sql, $m)) {
			foreach ($this->tables[$m[1]] ?? [] as $r) {
				if ((int) ($r['post_id'] ?? 0) === (int) $m[2]) return $r;
			}
			return null;
		}
		if (preg_match('/FROM\s+([a-zA-Z0-9_]+rewrite_snapshots)\s+WHERE\s+draft_id\s*=\s*(\d+)/i', $sql, $m)) {
			$found = null;
			foreach ($this->tables[$m[1]] ?? [] as $r) {
				if ((int) ($r['draft_id'] ?? 0) === (int) $m[2]) $found = $r;
			}
			return $found;
		}
		return null;
	}

	public function get_results(string $sql, string $output = ARRAY_A): array {
		if (preg_match('/FROM\s+([a-zA-Z0-9_]+)\s/i', $sql, $m)) {
			$rows = array_values($this->tables[$m[1]] ?? []);
			if (str_contains($sql, 'GROUP BY status')) {
				$counts = [];
				foreach ($rows as $r) {
					$st = $r['status'];
					$counts[$st] = ($counts[$st] ?? 0) + 1;
				}
				$out = [];
				foreach ($counts as $st => $cnt) {
					$out[] = ['status' => $st, 'cnt' => $cnt];
				}
				return $out;
			}
			if (preg_match('/target_url\s*=\s*\'([^\']+)\'/i', $sql, $um)) {
				$rows = array_values(array_filter($rows, static fn($r) => ($r['target_url'] ?? '') === $um[1]));
			}
			if (preg_match('/draft_id\s*=\s*(\d+)/i', $sql, $dm)) {
				$rows = array_values(array_filter($rows, static fn($r) => (int) ($r['draft_id'] ?? 0) === (int) $dm[1]));
			}
			if (preg_match('/keyword\s+LIKE\s+\'%([^\']+)%\'/i', $sql, $km)) {
				$rows = array_values(array_filter($rows, static fn($r) => stripos((string) ($r['keyword'] ?? ''), $km[1]) !== false));
			}
			if (preg_match('/status\s*=\s*\'([^\']+)\'/i', $sql, $sm)) {
				$rows = array_values(array_filter($rows, static fn($r) => ($r['status'] ?? '') === $sm[1]));
			}
			if (preg_match('/module\s*=\s*\'([^\']+)\'/i', $sql, $mm)) {
				$rows = array_values(array_filter($rows, static fn($r) => ($r['module'] ?? '') === $mm[1]));
			}
			if (preg_match('/source\s*=\s*\'([^\']+)\'/i', $sql, $sm2)) {
				$rows = array_values(array_filter($rows, static fn($r) => ($r['source'] ?? '') === $sm2[1]));
			}
			return array_reverse($rows);
		}
		return [];
	}
}
