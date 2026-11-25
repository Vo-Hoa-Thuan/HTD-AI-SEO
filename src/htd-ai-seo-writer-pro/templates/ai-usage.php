<?php if (!defined('ABSPATH')) { exit; } $tot = (array) ($usage['totals'] ?? []); ?>
<div class="wrap htd-wrap">
	<h1>AI Usage &amp; Token Accounting</h1>
	<div class="htd-grid">
		<div class="htd-card"><h3>Total Requests</h3><p><strong><?php echo esc_html((string) ($tot['requests'] ?? 0)); ?></strong> (Success: <?php echo esc_html((string) ($tot['success'] ?? 0)); ?> / Failed: <?php echo esc_html((string) ($tot['failed'] ?? 0)); ?>)</p></div>
		<div class="htd-card"><h3>Tokens</h3><p>In: <strong><?php echo esc_html((string) ($tot['input_tokens'] ?? 0)); ?></strong> | Out: <strong><?php echo esc_html((string) ($tot['output_tokens'] ?? 0)); ?></strong></p></div>
		<div class="htd-card"><h3>Estimated Cost (USD)</h3><p><strong>$<?php echo esc_html(number_format((float) ($tot['cost_usd'] ?? 0.0), 4)); ?></strong></p></div>
		<div class="htd-card"><h3>Cache Hits</h3><p><strong><?php echo esc_html((string) ($tot['cache_hits'] ?? 0)); ?></strong></p></div>
	</div>
</div>
