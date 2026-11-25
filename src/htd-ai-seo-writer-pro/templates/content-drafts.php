<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>Content Drafts &amp; Content QA</h1>
	<div class="htd-grid" style="margin-bottom:16px;">
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="htd-card">
			<?php wp_nonce_field('htd_ai_seo_generate_article'); ?>
			<input type="hidden" name="action" value="htd_ai_seo_generate_article">
			<h3>Generate SEO Article</h3>
			<p><input type="text" name="primary_keyword" class="regular-text" required placeholder="Primary keyword"></p>
			<button type="submit" class="button button-primary">Generate Draft</button>
		</form>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="htd-card">
			<?php wp_nonce_field('htd_ai_seo_bulk_generate'); ?>
			<input type="hidden" name="action" value="htd_ai_seo_bulk_generate">
			<h3>Bulk Generation (Queue)</h3>
			<p><textarea name="keywords_bulk" rows="3" class="large-text" placeholder="One keyword per line"></textarea></p>
			<button type="submit" class="button button-secondary">Enqueue Bulk Jobs</button>
		</form>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="htd-card">
			<?php wp_nonce_field('htd_ai_seo_media_action'); ?>
			<input type="hidden" name="action" value="htd_ai_seo_media_action">
			<h3>Image Generation</h3>
			<p><input type="text" name="image_prompt" class="regular-text" required placeholder="Image prompt"></p>
			<button type="submit" class="button button-secondary">Generate Image</button>
		</form>
	</div>
	<table class="widefat striped">
		<thead><tr><th>ID</th><th>Type</th><th>Title &amp; Edit</th><th>Slug</th><th>Content QA</th><th>Approval</th><th>Actions</th></tr></thead>
		<tbody>
		<?php if (empty($drafts)) : ?>
			<tr><td colspan="7">No internal content drafts created yet.</td></tr>
		<?php else : foreach ($drafts as $d) : ?>
			<tr>
				<td><?php echo esc_html((string) $d['id']); ?></td>
				<td><?php echo esc_html((string) $d['job_type']); ?></td>
				<td>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('htd_ai_seo_edit_draft'); ?>
						<input type="hidden" name="action" value="htd_ai_seo_edit_draft">
						<input type="hidden" name="draft_id" value="<?php echo esc_attr((string) $d['id']); ?>">
						<input type="text" name="title" value="<?php echo esc_attr((string) $d['title']); ?>" class="regular-text"><br>
						<input type="text" name="meta_title" value="<?php echo esc_attr((string) ($d['meta_title'] ?? '')); ?>" class="regular-text" placeholder="Meta Title" style="margin-top:4px;"><br>
						<textarea name="html_content" rows="2" class="large-text" style="margin-top:4px;"><?php echo esc_textarea((string) $d['html_content']); ?></textarea><br>
						<button type="submit" class="button button-small">Save Edit</button>
					</form>
				</td>
				<td><code><?php echo esc_html((string) ($d['slug'] ?? '')); ?></code></td>
				<td><span class="htd-badge <?php echo $d['qa_status'] === 'PASS' ? 'htd-badge-ok' : 'htd-badge-warn'; ?>"><?php echo esc_html((string) $d['qa_status']); ?></span><br><small><?php echo esc_html((string) $d['status']); ?></small></td>
				<td><?php echo esc_html((string) ($d['approval_id'] ?? '—')); ?></td>
				<td>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
						<?php wp_nonce_field('htd_ai_seo_export_draft'); ?>
						<input type="hidden" name="action" value="htd_ai_seo_export_draft">
						<input type="hidden" name="draft_id" value="<?php echo esc_attr((string) $d['id']); ?>">
						<input type="hidden" name="format" value="markdown">
						<button type="submit" class="button button-small">Export MD</button>
					</form>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
						<?php wp_nonce_field('htd_ai_seo_internal_links'); ?>
						<input type="hidden" name="action" value="htd_ai_seo_internal_links">
						<input type="hidden" name="draft_id" value="<?php echo esc_attr((string) $d['id']); ?>">
						<button type="submit" class="button button-small">Internal Links</button>
					</form>
					<?php if (!empty($d['approval_id'])) : ?>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
							<?php wp_nonce_field('htd_ai_seo_execute_approval'); ?>
							<input type="hidden" name="action" value="htd_ai_seo_execute_approval">
							<input type="hidden" name="approval_id" value="<?php echo esc_attr((string) $d['approval_id']); ?>">
							<input type="hidden" name="draft_id" value="<?php echo esc_attr((string) $d['id']); ?>">
							<input type="hidden" name="target_status" value="draft">
							<button type="submit" class="button button-primary button-small">Execute Approved</button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>
</div>
