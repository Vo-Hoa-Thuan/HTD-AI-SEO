<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>HTD Local Prompt Library (<?php echo esc_html((string) count((array) $prompts)); ?> Templates)</h1>
	<p class="description">100% local HTD-authored prompts. Zero calls to softviet.vn.</p>
	<table class="widefat striped">
		<thead><tr><th>ID</th><th>Name</th><th>Task Type</th><th>Version</th><th>Template &amp; Versioning</th><th>Status</th></tr></thead>
		<tbody>
		<?php foreach ((array) $prompts as $tpl) : ?>
			<tr>
				<td><code><?php echo esc_html($tpl->id); ?></code></td>
				<td><strong><?php echo esc_html($tpl->name); ?></strong></td>
				<td><?php echo esc_html($tpl->taskType); ?></td>
				<td>v<?php echo esc_html($tpl->version); ?></td>
				<td>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('htd_ai_seo_save_prompt'); ?>
						<input type="hidden" name="action" value="htd_ai_seo_save_prompt">
						<input type="hidden" name="prompt_id" value="<?php echo esc_attr($tpl->id); ?>">
						<textarea name="user_template" rows="2" class="large-text"><?php echo esc_textarea($tpl->userTemplate); ?></textarea>
						<button type="submit" class="button button-small" style="margin-top:4px;">Save New Version</button>
					</form>
				</td>
				<td><span class="htd-badge <?php echo $tpl->status === 'active' ? 'htd-badge-ok' : 'htd-badge-muted'; ?>"><?php echo esc_html($tpl->status); ?></span></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
