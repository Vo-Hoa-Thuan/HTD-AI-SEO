<?php
declare(strict_types=1);

namespace HTD\AISEO\Growth;

use HTD\AISEO\Database\Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Content Opportunity & SEO Roadmap Engine (strict 6-column timeline export).
 */
final class OpportunityAndRoadmapEngine {
	/** @var array<string, string[]> */
	public const HTD_SEED_KEYWORDS = [
		'camera_hanh_trinh' => [
			'camera hành trình ô tô', 'camera hành trình cho ô tô', 'mua camera hành trình ô tô',
			'camera hành trình 4K', 'camera hành trình 3 mắt', 'camera hành trình 4G',
			'camera hành trình có ADAS', 'camera hành trình ADAS', 'camera hành trình 3 kênh',
			'camera hành trình HD DVR', 'dash cam', 'dash camera', 'camera hành trình CS25',
		],
		'android_box' => [
			'android box ô tô', 'android box cho ô tô', 'mua android box ô tô', 'android box ô tô loại nào tốt',
		],
	];

	public function __construct(private readonly object $wpdb) {}

	/**
	 * Detect opportunities from real GSC rows; returns INSUFFICIENT_DATA if empty.
	 *
	 * @param array<int, array<string, mixed>> $gscRows
	 * @return array<int, array<string, mixed>>
	 */
	public function detectFromGsc(array $gscRows, string $provenance = 'IMPORTED_VERIFIED'): array {
		if ($gscRows === []) {
			return [[
				'priority'            => 'P3',
				'opportunity_type'    => 'INSUFFICIENT_DATA',
				'keyword'             => '',
				'target_url'          => '',
				'evidence_text'       => 'No GSC rows available; import or sync GSC data first.',
				'recommended_action'  => 'TECHNICAL_SEO_REVIEW',
				'verification_status' => 'INSUFFICIENT_DATA',
			]];
		}

		$opps = [];
		$table = Schema::table($this->wpdb, 'opportunities');
		$now = gmdate('Y-m-d H:i:s');

		foreach ($gscRows as $row) {
			$kw = (string) ($row['query'] ?? '');
			$url = (string) ($row['page'] ?? '');
			$imp = (int) ($row['impressions'] ?? 0);
			$clicks = (int) ($row['clicks'] ?? 0);
			$ctr = (float) ($row['ctr'] ?? 0.0);
			$pos = (float) ($row['position'] ?? 0.0);

			$type = null;
			$prio = 'P2';
			$action = 'UPDATE_CONTENT';

			if ($imp >= 500 && $ctr < 0.02) {
				$type = 'HIGH_IMPRESSIONS_LOW_CTR';
				$prio = 'P0';
				$action = 'CTR_OPTIMIZATION';
			} elseif ($pos >= 4.0 && $pos <= 10.0) {
				$type = 'RANKING_POS_4_10';
				$prio = 'P1';
				$action = 'UPDATE_CONTENT';
			} elseif ($pos > 10.0 && $pos <= 20.0) {
				$type = 'RANKING_POS_11_20';
				$prio = 'P1';
				$action = 'CONTENT_REFRESH';
			} elseif ($pos > 20.0 && $pos <= 50.0) {
				$type = 'RANKING_POS_21_50';
				$prio = 'P2';
				$action = 'INTERNAL_LINK';
			}

			if ($type !== null) {
				// Explainable priority score: impressions * (1 / max(1, pos))
				$score = round($imp / max(1.0, $pos), 2);
				$item = [
					'priority'            => $prio,
					'opportunity_type'    => $type,
					'keyword'             => $kw,
					'target_url'          => $url,
					'metrics_json'        => (string) json_encode(['clicks' => $clicks, 'impressions' => $imp, 'ctr' => $ctr, 'position' => $pos, 'priority_score' => $score, 'formula' => 'impressions / max(1, position)']),
					'evidence_text'       => "Imp={$imp}, Clicks={$clicks}, CTR=" . round($ctr * 100, 2) . "%, Pos={$pos} ({$provenance})",
					'recommended_action'  => $action,
					'risk_level'          => 'LOW_RISK',
					'verification_status' => $provenance,
					'status'              => 'OPEN',
					'created_at_utc'      => $now,
				];
				$this->wpdb->insert($table, $item);
				$item['id'] = (int) $this->wpdb->insert_id;
				$opps[] = $item;
			}
		}

		return $opps;
	}

	/**
	 * Build 6-column SEO roadmap CSV: TỚI NGÀY | NVPT | LOẠI BÀI ĐĂNG | TODO | LINK CONTENT | SOURCE
	 *
	 * @param array<int, array<string, mixed>> $opportunities
	 */
	public function exportRoadmapCsv(array $opportunities, string $owner = 'HTD SEO Team', int $horizonDays = 30): string {
		$fh = fopen('php://temp', 'r+');
		if ($fh === false) {
			return '';
		}
		fputcsv($fh, ['TỚI NGÀY', 'NVPT', 'LOẠI BÀI ĐĂNG', 'TODO', 'LINK CONTENT', 'SOURCE']);
		$dueDate = gmdate('Y-m-d', time() + (max(7, $horizonDays) * 86400));
		foreach ($opportunities as $opp) {
			fputcsv($fh, [
				$dueDate,
				$owner,
				(string) ($opp['recommended_action'] ?? 'UPDATE_CONTENT'),
				trim((string) (($opp['keyword'] ?? '') . ' — ' . ($opp['evidence_text'] ?? ''))),
				(string) ($opp['target_url'] ?? ''),
				(string) ($opp['verification_status'] ?? 'LOCAL_OBSERVED'),
			]);
		}
		rewind($fh);
		$csv = (string) stream_get_contents($fh);
		fclose($fh);
		return $csv;
	}
}
