<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>Security &amp; Guardrails</h1>
	<table class="widefat striped">
		<tbody>
			<tr><th>WP_READ_ONLY_MODE</th><td><?php echo !empty($settings['wp_read_only_mode']) ? 'TRUE (LIVE writes blocked)' : 'FALSE'; ?></td></tr>
			<tr><th>EMERGENCY_STOP</th><td><?php echo !empty($settings['emergency_stop']) ? 'ACTIVE' : 'INACTIVE'; ?></td></tr>
			<tr><th>ALLOW_EXTERNAL_AI_REQUESTS</th><td><?php echo !empty($settings['allow_external_ai_requests']) ? 'TRUE (Allowlisted HTTPS only)' : 'FALSE (Default Deny)'; ?></td></tr>
			<tr><th>ALLOW_EXTERNAL_TELEMETRY</th><td><?php echo !empty($settings['allow_external_telemetry']) ? 'TRUE' : 'FALSE (Zero Telemetry)'; ?></td></tr>
			<tr><th>ALLOW_REMOTE_UPDATE</th><td><?php echo !empty($settings['allow_remote_update']) ? 'TRUE' : 'FALSE'; ?></td></tr>
			<tr><th>SoftViet Endpoints</th><td>BLOCKED (Removed from HTD Core)</td></tr>
		</tbody>
	</table>
</div>
