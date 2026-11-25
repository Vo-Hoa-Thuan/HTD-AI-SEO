<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>HTD AI SEO Writer Pro — Dashboard</h1>
	<div class="htd-grid">
		<div class="htd-card">
			<h3>Runtime Versions</h3>
			<p>Plugin: <strong><?php echo esc_html((string) $health['plugin_version']); ?></strong></p>
			<p>PHP: <strong><?php echo esc_html((string) $health['php_version']); ?></strong></p>
			<p>WordPress: <strong><?php echo esc_html((string) $health['wp_version']); ?></strong></p>
			<p>DB Schema: <strong><?php echo esc_html((string) $health['db_schema_version']); ?></strong></p>
		</div>
		<div class="htd-card">
			<h3>Safety Controls</h3>
			<p>WP_READ_ONLY_MODE: <span class="htd-badge <?php echo $health['wp_read_only_mode'] ? 'htd-badge-ok' : 'htd-badge-warn'; ?>"><?php echo $health['wp_read_only_mode'] ? 'ENABLED (SAFE)' : 'DISABLED'; ?></span></p>
			<p>EMERGENCY_STOP: <span class="htd-badge <?php echo $health['emergency_stop'] ? 'htd-badge-danger' : 'htd-badge-ok'; ?>"><?php echo $health['emergency_stop'] ? 'ACTIVE' : 'INACTIVE'; ?></span></p>
			<p>Autopublish: <strong><?php echo $health['autopublish'] ? 'ON' : 'OFF'; ?></strong></p>
			<p>Human Approval: <strong><?php echo $health['human_approval'] ? 'REQUIRED' : 'OPTIONAL'; ?></strong></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('htd_ai_seo_toggle_emergency'); ?>
				<input type="hidden" name="action" value="htd_ai_seo_toggle_emergency">
				<button type="submit" class="button <?php echo $health['emergency_stop'] ? 'button-primary' : 'button-secondary'; ?>">
					<?php echo $health['emergency_stop'] ? 'Deactivate Emergency Stop' : 'Activate Emergency Stop'; ?>
				</button>
			</form>
		</div>
		<div class="htd-card">
			<h3>Integrations &amp; Queue</h3>
			<p>WooCommerce: <strong><?php echo $health['woocommerce_active'] ? 'Active' : 'Not Active'; ?></strong></p>
			<p>Rank Math: <strong><?php echo $health['rank_math_active'] ? 'Active' : 'Not Active'; ?></strong></p>
			<p>OpenSSL AES-256-GCM: <strong><?php echo $health['openssl_available'] ? 'Available' : 'Missing'; ?></strong></p>
			<p>Queue Jobs: <strong><?php echo esc_html((string) array_sum((array) $health['queue_counts'])); ?></strong></p>
		</div>
	</div>
	<h2>Module Status</h2>
	<table class="widefat striped">
		<thead><tr><th>Module</th><th>Phase</th><th>Status</th><th>Note</th></tr></thead>
		<tbody>
		<?php foreach ((array) $health['modules'] as $name => $mod) : ?>
			<tr>
				<td><code><?php echo esc_html((string) $name); ?></code></td>
				<td><?php echo esc_html((string) $mod['phase']); ?></td>
				<td><span class="htd-badge <?php echo $mod['status'] === 'ACTIVE' ? 'htd-badge-ok' : 'htd-badge-muted'; ?>"><?php echo esc_html((string) $mod['status']); ?></span></td>
				<td><?php echo esc_html((string) ($mod['note'] ?: 'Operational')); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
