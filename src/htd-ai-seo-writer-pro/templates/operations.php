<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>Operations Center — Queue, Cron &amp; Rank Math</h1>
	<div class="htd-grid">
		<div class="htd-card">
			<h3>Queue Status</h3>
			<p>Pending: <strong><?php echo esc_html((string) ($queue['PENDING'] ?? 0)); ?></strong></p>
			<p>Running: <strong><?php echo esc_html((string) ($queue['RUNNING'] ?? 0)); ?></strong></p>
			<p>Completed: <strong><?php echo esc_html((string) ($queue['COMPLETED'] ?? 0)); ?></strong></p>
			<p>Failed / Dead-letter: <strong><?php echo esc_html((string) (($queue['FAILED'] ?? 0) + ($queue['DEAD_LETTER'] ?? 0))); ?></strong></p>
		</div>
		<div class="htd-card">
			<h3>Cron Scheduler</h3>
			<p>Last Run (UTC): <strong><?php echo esc_html((string) ($cron['last_run_utc'] ?? 'Never')); ?></strong></p>
			<p>Last Status: <strong><?php echo esc_html((string) ($cron['last_status'] ?? 'IDLE')); ?></strong></p>
			<p>Background Generation: <strong><?php echo !empty($settings['allow_background_content_generation']) ? 'ENABLED' : 'DISABLED (SAFE)'; ?></strong></p>
			<p>Emergency Stop: <strong><?php echo !empty($settings['emergency_stop']) ? 'ACTIVE' : 'INACTIVE'; ?></strong></p>
		</div>
		<div class="htd-card">
			<h3>SEO &amp; Safety</h3>
			<p>Rank Math Integration: <strong><?php echo !empty($rankMath) ? 'Active' : 'Unavailable'; ?></strong></p>
			<p>WP_READ_ONLY_MODE: <strong><?php echo !empty($settings['wp_read_only_mode']) ? 'TRUE' : 'FALSE'; ?></strong></p>
			<p>Autopublish: <strong><?php echo !empty($settings['allow_autopublish']) ? 'ON' : 'OFF'; ?></strong></p>
		</div>
	</div>
</div>
