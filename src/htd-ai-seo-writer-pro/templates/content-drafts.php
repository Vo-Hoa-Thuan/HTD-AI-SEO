<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>Content Drafts &amp; Content QA</h1>
	<table class="widefat striped">
		<thead><tr><th>ID</th><th>Type</th><th>Title</th><th>Slug</th><th>Content QA</th><th>Approval ID</th><th>Status</th></tr></thead>
		<tbody>
		<?php if (empty($drafts)) : ?>
			<tr><td colspan="7">No internal content drafts created yet.</td></tr>
		<?php else : foreach ($drafts as $d) : ?>
			<tr>
				<td><?php echo esc_html((string) $d['id']); ?></td>
				<td><?php echo esc_html((string) $d['job_type']); ?></td>
				<td><strong><?php echo esc_html((string) $d['title']); ?></strong></td>
				<td><code><?php echo esc_html((string) ($d['slug'] ?? '')); ?></code></td>
				<td><span class="htd-badge <?php echo $d['qa_status'] === 'PASS' ? 'htd-badge-ok' : 'htd-badge-warn'; ?>"><?php echo esc_html((string) $d['qa_status']); ?></span></td>
				<td><?php echo esc_html((string) ($d['approval_id'] ?? '—')); ?></td>
				<td><?php echo esc_html((string) $d['status']); ?></td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>
</div>
