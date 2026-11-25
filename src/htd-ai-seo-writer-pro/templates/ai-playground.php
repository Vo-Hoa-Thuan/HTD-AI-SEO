<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>AI Test Playground (Preview Mode Default)</h1>
	<p class="description">Defaults to offline prompt preview. Live API requests require explicit ALLOW_EXTERNAL_AI_REQUESTS=true and valid Vault credentials.</p>
	<p><strong>ALLOW_EXTERNAL_AI_REQUESTS:</strong> <?php echo !empty($settings['allow_external_ai_requests']) ? 'TRUE' : 'FALSE (Offline Preview Only)'; ?></p>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="htd-card" style="max-width:720px;margin-bottom:16px;">
		<?php wp_nonce_field('htd_ai_seo_playground_run'); ?>
		<input type="hidden" name="action" value="htd_ai_seo_playground_run">
		<p><label>Provider:
			<select name="provider_id">
				<?php foreach ((array) ($configs ?? []) as $pid => $cfg) : ?>
					<option value="<?php echo esc_attr((string) $pid); ?>"><?php echo esc_html(strtoupper((string) $pid) . (!empty($cfg['enabled']) ? ' (Enabled)' : ' (Disabled)')); ?></option>
				<?php endforeach; ?>
			</select>
		</label></p>
		<p><label>Prompt Template:
			<select name="template_id">
				<?php foreach ((array) ($prompts ?? []) as $tid => $tpl) : ?>
					<option value="<?php echo esc_attr((string) $tid); ?>"><?php echo esc_html($tpl->name . ' (' . $tid . ')'); ?></option>
				<?php endforeach; ?>
			</select>
		</label></p>
		<p><label>Keyword / Topic:<br><input type="text" name="keyword" class="regular-text" required placeholder="e.g. camera hành trình ô tô HTD"></label></p>
		<p><label>Execution Mode:
			<select name="mode">
				<option value="preview">Offline Prompt Preview (Safe)</option>
				<option value="live">Live AI Execution (Requires ALLOW_EXTERNAL_AI_REQUESTS=true)</option>
			</select>
		</label></p>
		<button type="submit" class="button button-primary">Run Playground</button>
	</form>
	<?php if (!empty($lastResult) && is_array($lastResult)) : ?>
		<div class="htd-card">
			<h3>Last Result (<?php echo esc_html((string) ($lastResult['mode'] ?? '')); ?>)</h3>
			<pre style="white-space:pre-wrap;max-height:360px;overflow:auto;"><?php echo esc_html(isset($lastResult['text']) ? (string) $lastResult['text'] : (string) json_encode($lastResult['messages'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
		</div>
	<?php endif; ?>
</div>
