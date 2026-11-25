<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>AI Providers (Official APIs Only)</h1>
	<p class="description">API keys are stored encrypted in the HTD Credential Vault and never exposed in HTML/JS.</p>
	<table class="widefat striped">
		<thead><tr><th>Provider</th><th>Enabled</th><th>Model</th><th>Max Tokens</th><th>Timeout</th><th>Daily Budget (USD)</th><th>Credential</th><th>Action</th></tr></thead>
		<tbody>
		<?php foreach ((array) $configs as $id => $cfg) : ?>
			<tr>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('htd_ai_seo_save_provider'); ?>
					<input type="hidden" name="action" value="htd_ai_seo_save_provider">
					<input type="hidden" name="provider_id" value="<?php echo esc_attr((string) $id); ?>">
					<td><strong><?php echo esc_html(strtoupper((string) $id)); ?></strong></td>
					<td><input type="checkbox" name="enabled" value="1" <?php checked(!empty($cfg['enabled'])); ?>></td>
					<td><input type="text" name="model" value="<?php echo esc_attr((string) $cfg['model']); ?>" class="regular-text"></td>
					<td><input type="number" name="max_tokens" value="<?php echo esc_attr((string) $cfg['max_tokens']); ?>" min="64" max="32768" style="width:80px"></td>
					<td><input type="number" name="timeout" value="<?php echo esc_attr((string) $cfg['timeout']); ?>" min="5" max="60" style="width:65px">s</td>
					<td><input type="number" step="0.1" name="daily_budget_usd" value="<?php echo esc_attr((string) $cfg['daily_budget_usd']); ?>" style="width:80px"></td>
					<td><code><?php echo esc_html((string) ($maskedCreds[$cfg['cred_ref']]['masked'] ?? 'Not Set')); ?></code></td>
					<td><button type="submit" class="button button-primary button-small">Save</button></td>
				</form>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
