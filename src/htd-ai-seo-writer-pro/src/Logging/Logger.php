<?php
declare(strict_types=1);

namespace HTD\AISEO\Logging;

use HTD\AISEO\Database\Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Structured audit & security logger with automatic secret redaction.
 */
final class Logger {
	public const LEVELS = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL', 'SECURITY', 'AUDIT'];

	/** @var string[] */
	private const SENSITIVE_KEYS = [
		'api_key', 'apikey', 'token', 'access_token', 'refresh_token', 'id_token',
		'secret', 'password', 'passwd', 'authorization', 'auth', 'bearer', 'credential', 'prompt_raw',
	];

	private string $correlationId;

	public function __construct(private readonly object $wpdb, ?string $correlationId = null) {
		$this->correlationId = $correlationId ?? bin2hex(random_bytes(8));
	}

	public function correlationId(): string {
		return $this->correlationId;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function log(
		string $module,
		string $action,
		string $severity = 'INFO',
		string $result = 'OK',
		array $context = [],
		int $durationMs = 0
	): void {
		$sev = in_array(strtoupper($severity), self::LEVELS, true) ? strtoupper($severity) : 'INFO';
		$actorId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
		$redacted = self::redactArray($context);
		$json = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			$json = '{}';
		}
		$json = self::redactString($json);

		$table = Schema::table($this->wpdb, 'logs');
		$this->wpdb->insert($table, [
			'correlation_id' => $this->correlationId,
			'event_id'       => bin2hex(random_bytes(8)),
			'module'         => substr($module, 0, 64),
			'action'         => substr($action, 0, 80),
			'actor_id'       => $actorId,
			'severity'       => $sev,
			'result'         => substr($result, 0, 32),
			'context_json'   => $json,
			'duration_ms'    => max(0, $durationMs),
			'created_at_utc' => gmdate('Y-m-d H:i:s'),
		]);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function redactArray(array $data): array {
		$out = [];
		foreach ($data as $k => $v) {
			$keyLower = strtolower((string) $k);
			$isSensitive = false;
			foreach (self::SENSITIVE_KEYS as $sk) {
				if (str_contains($keyLower, $sk)) {
					$isSensitive = true;
					break;
				}
			}
			if ($isSensitive) {
				$out[$k] = '***REDACTED***';
			} elseif (is_array($v)) {
				$out[$k] = self::redactArray($v);
			} elseif (is_string($v)) {
				$out[$k] = self::redactString($v);
			} else {
				$out[$k] = $v;
			}
		}
		return $out;
	}

	public static function redactString(string $text): string {
		$patterns = [
			'/sk-[A-Za-z0-9_\-]{8,}/'                => 'sk-***REDACTED***',
			'/AIza[0-9A-Za-z\-_]{20,}/'              => 'AIza***REDACTED***',
			'/sk-ant-[A-Za-z0-9_\-]{8,}/'            => 'sk-ant-***REDACTED***',
			'/Bearer\s+[A-Za-z0-9\._\-]{8,}/i'       => 'Bearer ***REDACTED***',
		];
		return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function query(int $limit = 50, int $offset = 0, ?string $severity = null, ?string $module = null): array {
		$table = Schema::table($this->wpdb, 'logs');
		$where = [];
		$params = [];
		if ($severity !== null && $severity !== '') {
			$where[] = 'severity = %s';
			$params[] = strtoupper($severity);
		}
		if ($module !== null && $module !== '') {
			$where[] = 'module = %s';
			$params[] = $module;
		}
		$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
		$params[] = max(1, min(500, $limit));
		$params[] = max(0, $offset);

		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$table} {$whereSql} ORDER BY id DESC LIMIT %d OFFSET %d",
			...$params
		);
		$rows = $this->wpdb->get_results($sql, ARRAY_A);
		return is_array($rows) ? $rows : [];
	}

	public function pruneOlderThanDays(int $days): int {
		$days = max(1, $days);
		$cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
		$table = Schema::table($this->wpdb, 'logs');
		$res = $this->wpdb->query(
			$this->wpdb->prepare("DELETE FROM {$table} WHERE created_at_utc < %s", $cutoff)
		);
		return is_int($res) ? $res : 0;
	}
}
