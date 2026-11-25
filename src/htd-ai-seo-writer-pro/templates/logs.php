<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>Audit &amp; Security Logs</h1>
	<table class="widefat striped">
		<thead><tr><th>ID</th><th>Time (UTC)</th><th>Module</th><th>Action</th><th>Severity</th><th>Result</th><th>Context</th></tr></thead>
		<tbody>
		<?php if (empty($logs)) : ?>
			<tr><td colspan="7">No log entries recorded yet.</td></tr>
		<?php else : foreach ($logs as $row) : ?>
			<tr>
				<td><?php echo esc_html((string) $row['id']); ?></td>
				<td><?php echo esc_html((string) $row['created_at_utc']); ?></td>
				<td><?php echo esc_html((string) $row['module']); ?></td>
				<td><?php echo esc_html((string) $row['action']); ?></td>
				<td><?php echo esc_html((string) $row['severity']); ?></td>
				<td><?php echo esc_html((string) $row['result']); ?></td>
				<td><code><?php echo esc_html((string) $row['context_json']); ?></code></td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>
</div>
