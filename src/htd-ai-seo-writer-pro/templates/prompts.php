<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>HTD Local Prompt Library (<?php echo esc_html((string) count((array) $prompts)); ?> Templates)</h1>
	<p class="description">100% local HTD-authored prompts. Zero calls to softviet.vn.</p>
	<table class="widefat striped">
		<thead><tr><th>ID</th><th>Name</th><th>Task Type</th><th>Version</th><th>Lang</th><th>Required Vars</th><th>Token Budget</th><th>Status</th></tr></thead>
		<tbody>
		<?php foreach ((array) $prompts as $tpl) : ?>
			<tr>
				<td><code><?php echo esc_html($tpl->id); ?></code></td>
				<td><strong><?php echo esc_html($tpl->name); ?></strong></td>
				<td><?php echo esc_html($tpl->taskType); ?></td>
				<td>v<?php echo esc_html($tpl->version); ?></td>
				<td><?php echo esc_html(strtoupper($tpl->language)); ?></td>
				<td><code><?php echo esc_html(implode(', ', $tpl->requiredVariables)); ?></code></td>
				<td><?php echo esc_html((string) $tpl->tokenBudget); ?></td>
				<td><span class="htd-badge <?php echo $tpl->status === 'active' ? 'htd-badge-ok' : 'htd-badge-muted'; ?>"><?php echo esc_html($tpl->status); ?></span></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
