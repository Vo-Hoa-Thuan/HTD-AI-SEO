<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>Keywords &amp; Queue Manager</h1>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
		<?php wp_nonce_field('htd_ai_seo_add_keyword'); ?>
		<input type="hidden" name="action" value="htd_ai_seo_add_keyword">
		<input type="text" name="keyword" placeholder="Primary keyword" class="regular-text" required>
		<input type="text" name="keyword_group" placeholder="Group (e.g. camera-hanh-trinh)" class="regular-text">
		<input type="url" name="target_url" placeholder="Target URL (optional)" class="regular-text">
		<input type="number" name="priority" value="5" min="1" max="10" style="width:65px">
		<button type="submit" class="button button-primary">Add Keyword</button>
	</form>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<?php wp_nonce_field('htd_ai_seo_bulk_keywords'); ?>
		<input type="hidden" name="action" value="htd_ai_seo_bulk_keywords">
		<select name="bulk_action">
			<option value="pause">Pause</option>
			<option value="resume">Resume</option>
			<option value="delete">Delete</option>
		</select>
		<button type="submit" class="button">Apply Bulk Action</button>
		<table class="widefat striped" style="margin-top:10px;">
			<thead><tr><th>Sel</th><th>ID</th><th>Keyword</th><th>Group</th><th>Intent (Source)</th><th>Target URL</th><th>Status</th></tr></thead>
			<tbody>
			<?php if (empty($keywords)) : ?>
				<tr><td colspan="7">No keywords in queue.</td></tr>
			<?php else : foreach ($keywords as $kw) : ?>
				<tr>
					<td><input type="checkbox" name="keyword_ids[]" value="<?php echo esc_attr((string) $kw['id']); ?>"></td>
					<td><?php echo esc_html((string) $kw['id']); ?></td>
					<td><strong><?php echo esc_html((string) $kw['keyword']); ?></strong></td>
					<td><?php echo esc_html((string) ($kw['keyword_group'] ?? '—')); ?></td>
					<td><?php echo esc_html((string) $kw['search_intent'] . ' (' . $kw['intent_source'] . ')'); ?></td>
					<td><?php echo esc_html((string) ($kw['target_url'] ?? '—')); ?></td>
					<td><span class="htd-badge htd-badge-muted"><?php echo esc_html((string) $kw['status']); ?></span></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	</form>
</div>
