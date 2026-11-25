<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>Rewrite Proposals &amp; Snapshots</h1>
	<p class="description">URL, slug, canonical, and product facts are preserved by default. Publishing requires Human Approval.</p>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="htd-card" style="max-width:720px;margin-bottom:16px;">
		<?php wp_nonce_field('htd_ai_seo_propose_rewrite'); ?>
		<input type="hidden" name="action" value="htd_ai_seo_propose_rewrite">
		<h3>Propose Post Rewrite</h3>
		<p><label>WP Post ID: <input type="number" name="post_id" min="1" required></label></p>
		<p><label>Proposed Title:<br><input type="text" name="proposed_title" class="regular-text" required></label></p>
		<p><label>Proposed HTML:<br><textarea name="proposed_html" rows="4" class="large-text" required></textarea></label></p>
		<button type="submit" class="button button-primary">Create Safe Rewrite Proposal</button>
	</form>
	<table class="widefat striped">
		<thead><tr><th>Draft ID</th><th>Target Post ID</th><th>Title</th><th>Preserved Slug</th><th>QA Status</th><th>Approval ID</th></tr></thead>
		<tbody>
		<?php if (empty($drafts)) : ?>
			<tr><td colspan="6">No rewrite proposals yet.</td></tr>
		<?php else : foreach ($drafts as $d) : ?>
			<tr>
				<td><?php echo esc_html((string) $d['id']); ?></td>
				<td><?php echo esc_html((string) $d['target_post_id']); ?></td>
				<td><strong><?php echo esc_html((string) $d['title']); ?></strong></td>
				<td><code><?php echo esc_html((string) ($d['slug'] ?? '')); ?></code></td>
				<td><?php echo esc_html((string) $d['qa_status']); ?></td>
				<td><?php echo esc_html((string) ($d['approval_id'] ?? '—')); ?></td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>
</div>
