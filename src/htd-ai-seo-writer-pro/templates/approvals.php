<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>Human Approval Queue</h1>
	<table class="widefat striped">
		<thead><tr><th>ID</th><th>Action</th><th>Target</th><th>Status</th><th>Payload Hash</th><th>Actions</th></tr></thead>
		<tbody>
		<?php if (empty($approvals)) : ?>
			<tr><td colspan="6">No approval requests yet.</td></tr>
		<?php else : foreach ($approvals as $row) : ?>
			<tr>
				<td><?php echo esc_html((string) $row['id']); ?></td>
				<td><?php echo esc_html((string) $row['action_type']); ?></td>
				<td><?php echo esc_html((string) $row['target_type'] . '#' . $row['target_id']); ?></td>
				<td><strong><?php echo esc_html((string) $row['status']); ?></strong></td>
				<td><code><?php echo esc_html(substr((string) $row['payload_hash'], 0, 12)); ?>...</code></td>
				<td>
					<?php if ($row['status'] === 'REVIEW_PENDING') : ?>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
							<?php wp_nonce_field('htd_ai_seo_approval_decision'); ?>
							<input type="hidden" name="action" value="htd_ai_seo_approval_decision">
							<input type="hidden" name="approval_id" value="<?php echo esc_attr((string) $row['id']); ?>">
							<button type="submit" name="decision" value="approve" class="button button-primary button-small">Approve</button>
							<button type="submit" name="decision" value="reject" class="button button-secondary button-small">Reject</button>
						</form>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>
</div>
