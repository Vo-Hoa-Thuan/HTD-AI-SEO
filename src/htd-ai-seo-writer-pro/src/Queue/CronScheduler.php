<?php
declare(strict_types=1);

namespace HTD\AISEO\Queue;

use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * LiteSpeed/cPanel-compatible WordPress Cron scheduler with overlap lock and Emergency Stop enforcement.
 */
final class CronScheduler {
	public const HOOK_PROCESS    = 'htd_ai_seo_cron_process';
	public const STATE_OPTION    = 'htd_ai_seo_cron_state';
	public const LOCK_TRANSIENT  = 'htd_ai_seo_cron_lock';

	public function __construct(
		private readonly WorkerEngine $worker,
		private readonly SettingsRegistry $settings,
		private readonly Logger $logger
	) {}

	public function register(): void {
		if (!function_exists('add_filter') || !function_exists('add_action')) {
			return;
		}
		add_filter('cron_schedules', [$this, 'addSchedules']);
		add_action(self::HOOK_PROCESS, [$this, 'runScheduled']);
	}

	/**
	 * @param array<string, array<string, mixed>> $schedules
	 * @return array<string, array<string, mixed>>
	 */
	public function addSchedules(array $schedules): array {
		$schedules['htd_every_5_min']  = ['interval' => 300, 'display' => 'HTD Every 5 Minutes'];
		$schedules['htd_every_15_min'] = ['interval' => 900, 'display' => 'HTD Every 15 Minutes'];
		return $schedules;
	}

	public function ensureScheduled(string $recurrence = 'htd_every_15_min'): void {
		if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')) {
			if (!wp_next_scheduled(self::HOOK_PROCESS)) {
				wp_schedule_event(time() + 60, $recurrence, self::HOOK_PROCESS);
			}
		}
	}

	public static function clearScheduled(): void {
		if (function_exists('wp_clear_scheduled_hook')) {
			wp_clear_scheduled_hook(self::HOOK_PROCESS);
		}
	}

	/**
	 * Execute scheduled batch safely (overlap-locked, respects allow_background_content_generation & emergency_stop).
	 *
	 * @return array<string, mixed>
	 */
	public function runScheduled(int $batchSize = 2): array {
		if ((bool) $this->settings->get('emergency_stop', false)) {
			return $this->recordState('BLOCKED', 'EMERGENCY_STOP is active.');
		}
		if (!(bool) $this->settings->get('allow_background_content_generation', false)) {
			return $this->recordState('SKIPPED', 'ALLOW_BACKGROUND_CONTENT_GENERATION is false.');
		}

		if (function_exists('get_transient') && get_transient(self::LOCK_TRANSIENT)) {
			return $this->recordState('SKIPPED', 'Cron overlap lock active.');
		}
		if (function_exists('set_transient')) {
			set_transient(self::LOCK_TRANSIENT, 1, 120);
		}

		try {
			$workerId = 'cron-' . bin2hex(random_bytes(4));
			$res = $this->worker->runBatch($workerId, max(1, min(5, $batchSize)));
			return $this->recordState('OK', "Processed {$res['processed']}, Completed {$res['completed']}, Failed {$res['failed']}");
		} catch (\Throwable $e) {
			$this->logger->log('cron', 'run_failed', 'ERROR', 'FAILED', ['error' => $e->getMessage()]);
			return $this->recordState('ERROR', $e->getMessage());
		} finally {
			if (function_exists('delete_transient')) {
				delete_transient(self::LOCK_TRANSIENT);
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$raw = function_exists('get_option') ? get_option(self::STATE_OPTION, []) : [];
		return is_array($raw) ? $raw : [];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function recordState(string $status, string $message): array {
		$state = [
			'last_run_utc' => gmdate('Y-m-d H:i:s'),
			'last_status'  => $status,
			'last_message' => $message,
			'next_run'     => function_exists('wp_next_scheduled') ? (wp_next_scheduled(self::HOOK_PROCESS) ?: null) : null,
		];
		if (function_exists('update_option')) {
			update_option(self::STATE_OPTION, $state, false);
		}
		return $state;
	}
}
