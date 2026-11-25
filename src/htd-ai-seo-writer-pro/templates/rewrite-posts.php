<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>Rewrite Proposals &amp; Snapshots</h1>
	<p class="description">URL, slug, canonical, and product facts are preserved by default. Publishing requires Phase 05 + Human Approval.</p>
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
