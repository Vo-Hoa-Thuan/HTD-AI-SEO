<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>Credential Management (AES-256-GCM Vault)</h1>
	<?php if (!empty($vaultError)) : ?>
		<div class="notice notice-error"><p><?php echo esc_html((string) $vaultError); ?></p></div>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<?php wp_nonce_field('htd_ai_seo_save_credential'); ?>
			<input type="hidden" name="action" value="htd_ai_seo_save_credential">
			<table class="form-table">
				<tr>
					<th><label for="cred_name">Credential Key Name</label></th>
					<td><input type="text" id="cred_name" name="cred_name" placeholder="e.g. openai_primary" required></td>
				</tr>
				<tr>
					<th><label for="cred_secret">API Secret (Encrypted at rest, never displayed)</label></th>
					<td><input type="password" id="cred_secret" name="cred_secret" class="regular-text" autocomplete="off" required></td>
				</tr>
			</table>
			<?php submit_button('Seal &amp; Store Credential'); ?>
		</form>
		<h2>Stored Credentials</h2>
		<table class="widefat striped">
			<thead><tr><th>Name</th><th>Masked</th><th>Key Version</th><th>Updated (UTC)</th><th>Action</th></tr></thead>
			<tbody>
			<?php if (empty($credentials)) : ?>
				<tr><td colspan="5">No credentials stored.</td></tr>
			<?php else : foreach ($credentials as $name => $meta) : ?>
				<tr>
					<td><code><?php echo esc_html((string) $name); ?></code></td>
					<td><code><?php echo esc_html((string) $meta['masked']); ?></code></td>
					<td>v<?php echo esc_html((string) $meta['key_ver']); ?></td>
					<td><?php echo esc_html((string) $meta['updated_at']); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="htd-confirm-form" data-confirm="Delete this credential?">
							<?php wp_nonce_field('htd_ai_seo_delete_credential'); ?>
							<input type="hidden" name="action" value="htd_ai_seo_delete_credential">
							<input type="hidden" name="cred_name" value="<?php echo esc_attr((string) $name); ?>">
							<button type="submit" class="button button-link-delete">Delete</button>
						</form>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
