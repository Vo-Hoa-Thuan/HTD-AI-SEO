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
			<h3>GSC &amp; GA4 Sync (Read-Only Scopes)</h3>
			<p>GSC Snapshots: <strong><?php echo esc_html((string) ($gscCount ?? 0)); ?></strong> | GA4 Snapshots: <strong><?php echo esc_html((string) ($ga4Count ?? 0)); ?></strong></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:8px;">
				<?php wp_nonce_field('htd_ai_seo_google_sync'); ?>
				<input type="hidden" name="action" value="htd_ai_seo_google_sync">
				<select name="service"><option value="gsc">Google Search Console</option><option value="ga4">Google Analytics 4</option></select>
				<input type="text" name="property" placeholder="Site URL or GA4 Property ID" class="regular-text">
				<button type="submit" name="op" value="sync" class="button button-secondary">Sync Now</button>
				<button type="submit" name="op" value="disconnect" class="button">Disconnect</button>
			</form>
		</div>
		<div class="htd-card">
			<h3>Manual GSC CSV Import</h3>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('htd_ai_seo_gsc_import'); ?>
				<input type="hidden" name="action" value="htd_ai_seo_gsc_import">
				<p><input type="text" name="property" required placeholder="https://htd.net.vn/" class="regular-text">
				<select name="report_type"><option value="queries">queries</option><option value="pages">pages</option><option value="search_performance">search_performance</option></select></p>
				<p><textarea name="csv_content" rows="3" class="large-text" required placeholder="query,page,clicks,impressions,ctr,position"></textarea></p>
				<button type="submit" class="button button-secondary">Validate &amp; Import CSV</button>
			</form>
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
