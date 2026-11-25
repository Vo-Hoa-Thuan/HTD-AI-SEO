<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>HTD AI SEO Writer Pro — Settings</h1>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<?php wp_nonce_field('htd_ai_seo_save_settings'); ?>
		<input type="hidden" name="action" value="htd_ai_seo_save_settings">
		<table class="form-table">
			<?php foreach ($schema as $key => $def) : $val = $settings[$key] ?? $def['default']; ?>
			<tr>
				<th scope="row"><label for="htd_<?php echo esc_attr($key); ?>"><code><?php echo esc_html($key); ?></code></label></th>
				<td>
					<?php if ($def['type'] === 'bool') : ?>
						<input type="checkbox" id="htd_<?php echo esc_attr($key); ?>" name="settings[<?php echo esc_attr($key); ?>]" value="1" <?php checked((bool) $val); ?>>
					<?php elseif ($def['type'] === 'enum') : ?>
						<select id="htd_<?php echo esc_attr($key); ?>" name="settings[<?php echo esc_attr($key); ?>]">
							<?php foreach ((array) $def['values'] as $opt) : ?>
								<option value="<?php echo esc_attr($opt); ?>" <?php selected((string) $val, (string) $opt); ?>><?php echo esc_html($opt); ?></option>
							<?php endforeach; ?>
						</select>
					<?php elseif ($def['type'] === 'int') : ?>
						<input type="number" id="htd_<?php echo esc_attr($key); ?>" name="settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $val); ?>" min="<?php echo esc_attr((string) ($def['min'] ?? 0)); ?>" max="<?php echo esc_attr((string) ($def['max'] ?? 9999)); ?>">
					<?php else : ?>
						<input type="text" class="regular-text" id="htd_<?php echo esc_attr($key); ?>" name="settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr(is_array($val) ? implode(',', $val) : (string) $val); ?>">
					<?php endif; ?>
					<p class="description"><?php echo esc_html((string) $def['description']); ?></p>
				</td>
			</tr>
			<?php endforeach; ?>
		</table>
		<?php submit_button('Save HTD Settings'); ?>
	</form>
</div>
