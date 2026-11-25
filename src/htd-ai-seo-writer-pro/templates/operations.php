<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>Operations Center — Queue, Cron &amp; Rank Math</h1>
	<div class="htd-grid">
		<div class="htd-card">
			<h3>Queue Status &amp; Controls</h3>
			<p>Pending: <strong><?php echo esc_html((string) ($queue['PENDING'] ?? 0)); ?></strong> | Running: <strong><?php echo esc_html((string) ($queue['RUNNING'] ?? 0)); ?></strong></p>
			<p>Completed: <strong><?php echo esc_html((string) ($queue['COMPLETED'] ?? 0)); ?></strong> | Failed/DLQ: <strong><?php echo esc_html((string) (($queue['FAILED'] ?? 0) + ($queue['DEAD_LETTER'] ?? 0))); ?></strong></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
				<?php wp_nonce_field('htd_ai_seo_queue_action'); ?>
				<input type="hidden" name="action" value="htd_ai_seo_queue_action">
				<button type="submit" name="queue_op" value="pause" class="button">Pause Queue</button>
				<button type="submit" name="queue_op" value="resume" class="button">Resume Queue</button>
				<button type="submit" name="queue_op" value="run_cron" class="button button-primary">Safe Run Batch</button>
			</form>
		</div>
		<div class="htd-card">
			<h3>Cron Scheduler</h3>
			<p>Last Run (UTC): <strong><?php echo esc_html((string) ($cron['last_run_utc'] ?? 'Never')); ?></strong></p>
			<p>Last Status: <strong><?php echo esc_html((string) ($cron['last_status'] ?? 'IDLE')); ?></strong></p>
			<p>Background Generation: <strong><?php echo !empty($settings['allow_background_content_generation']) ? 'ENABLED' : 'DISABLED (SAFE)'; ?></strong></p>
		</div>
		<div class="htd-card">
			<h3>SEO &amp; Safety</h3>
			<p>Rank Math Integration: <strong><?php echo !empty($rankMath) ? 'Active' : 'Unavailable'; ?></strong></p>
			<p>WP_READ_ONLY_MODE: <strong><?php echo !empty($settings['wp_read_only_mode']) ? 'TRUE' : 'FALSE'; ?></strong></p>
		</div>
	</div>
	<h2>Recent Queue Jobs</h2>
	<table class="widefat striped">
		<thead><tr><th>ID</th><th>Job Type</th><th>Status</th><th>Attempts</th><th>Last Error</th><th>Actions</th></tr></thead>
		<tbody>
		<?php if (empty($jobs)) : ?>
			<tr><td colspan="6">No queue jobs found.</td></tr>
		<?php else : foreach ((array) $jobs as $j) : ?>
			<tr>
				<td><?php echo esc_html((string) $j['id']); ?></td>
				<td><code><?php echo esc_html((string) $j['job_type']); ?></code></td>
				<td><?php echo esc_html((string) $j['status']); ?></td>
				<td><?php echo esc_html((string) $j['attempts']); ?>/<?php echo esc_html((string) $j['max_attempts']); ?></td>
				<td><?php echo esc_html((string) ($j['last_error'] ?? '')); ?></td>
				<td>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
						<?php wp_nonce_field('htd_ai_seo_queue_action'); ?>
						<input type="hidden" name="action" value="htd_ai_seo_queue_action">
						<input type="hidden" name="job_id" value="<?php echo esc_attr((string) $j['id']); ?>">
						<button type="submit" name="queue_op" value="retry" class="button button-small">Retry</button>
						<button type="submit" name="queue_op" value="cancel" class="button button-small">Cancel</button>
					</form>
				</td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>
</div>
