<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>System Health</h1>
	<table class="widefat striped">
		<tbody>
			<tr><th>PHP Version</th><td><?php echo esc_html((string) $health['php_version']); ?> (Compatible: <?php echo $health['php_compatible'] ? 'Yes' : 'No'; ?>)</td></tr>
			<tr><th>WordPress Version</th><td><?php echo esc_html((string) $health['wp_version']); ?></td></tr>
			<tr><th>DB Schema Version</th><td><?php echo esc_html((string) $health['db_schema_version']); ?> / Target <?php echo esc_html((string) $health['db_target_version']); ?></td></tr>
			<tr><th>OpenSSL AES-256-GCM</th><td><?php echo $health['openssl_available'] ? 'Available' : 'Missing'; ?></td></tr>
			<tr><th>LiteSpeed Detected</th><td><?php echo $health['litespeed_detected'] ? 'Yes' : 'No'; ?></td></tr>
			<tr><th>WooCommerce</th><td><?php echo $health['woocommerce_active'] ? 'Active' : 'Not Active'; ?></td></tr>
			<tr><th>Rank Math</th><td><?php echo $health['rank_math_active'] ? 'Active' : 'Not Active'; ?></td></tr>
		</tbody>
	</table>
</div>
