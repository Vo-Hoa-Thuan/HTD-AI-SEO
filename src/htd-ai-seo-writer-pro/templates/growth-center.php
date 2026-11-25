<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>HTD Growth Center &amp; SEO Action Center</h1>
	<p class="description">Zero fabricated metrics. External GSC/GA4/AI Search data shows real imported/synced state or explicit BLOCKED/EMPTY status.</p>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
		<?php wp_nonce_field('htd_ai_seo_run_audit'); ?>
		<input type="hidden" name="action" value="htd_ai_seo_run_audit">
		<button type="submit" class="button button-primary">RUN HTD SEO AUDIT (Read-Only)</button>
	</form>
	<div class="htd-grid">
		<div class="htd-card">
			<h3>Data Sources</h3>
			<p>GSC Snapshots: <strong><?php echo esc_html((string) ($gscCount ?? 0)); ?></strong></p>
			<p>GA4 Snapshots: <strong><?php echo esc_html((string) ($ga4Count ?? 0)); ?></strong></p>
			<p>WP_READ_ONLY_MODE: <strong><?php echo !empty($settings['wp_read_only_mode']) ? 'TRUE (SAFE)' : 'FALSE'; ?></strong></p>
		</div>
		<div class="htd-card">
			<h3>Priority Categories</h3>
			<ul>
				<?php foreach ((array) ($brand['priority_urls'] ?? []) as $u) : ?>
					<li><code><?php echo esc_html((string) $u); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
	<h2>SEO Action Center (P0–P3 Opportunities)</h2>
	<table class="widefat striped">
		<thead><tr><th>Priority</th><th>Type</th><th>Keyword</th><th>Target URL</th><th>Evidence</th><th>Action</th><th>Status</th></tr></thead>
		<tbody>
		<?php if (empty($opportunities)) : ?>
			<tr><td colspan="7">No SEO opportunities recorded yet. Import GSC/GA4 data or run HTD SEO Audit.</td></tr>
		<?php else : foreach ($opportunities as $o) : ?>
			<tr>
				<td><strong><?php echo esc_html((string) $o['priority']); ?></strong></td>
				<td><?php echo esc_html((string) $o['opportunity_type']); ?></td>
				<td><?php echo esc_html((string) ($o['keyword'] ?? '—')); ?></td>
				<td><code><?php echo esc_html((string) ($o['target_url'] ?? '—')); ?></code></td>
				<td><?php echo esc_html((string) $o['evidence_text']); ?></td>
				<td><?php echo esc_html((string) $o['recommended_action']); ?></td>
				<td><?php echo esc_html((string) $o['status']); ?></td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>
</div>
