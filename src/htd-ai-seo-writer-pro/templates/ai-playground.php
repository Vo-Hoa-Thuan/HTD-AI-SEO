<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>AI Test Playground (Preview Mode Default)</h1>
	<p class="description">Defaults to offline prompt preview. Live API requests require explicit ALLOW_EXTERNAL_AI_REQUESTS=true and valid Vault credentials.</p>
	<p><strong>ALLOW_EXTERNAL_AI_REQUESTS:</strong> <?php echo !empty($settings['allow_external_ai_requests']) ? 'TRUE' : 'FALSE (Offline Preview Only)'; ?></p>
</div>
