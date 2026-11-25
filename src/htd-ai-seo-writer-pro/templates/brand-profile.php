<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>HTD Brand Profile</h1>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<?php wp_nonce_field('htd_ai_seo_save_brand'); ?>
		<input type="hidden" name="action" value="htd_ai_seo_save_brand">
		<table class="form-table">
			<tr><th>Brand Name</th><td><input type="text" name="brand_name" class="regular-text" value="<?php echo esc_attr((string) $brand['brand_name']); ?>"></td></tr>
			<tr><th>Website</th><td><input type="url" name="website" class="regular-text" value="<?php echo esc_attr((string) $brand['website']); ?>"></td></tr>
			<tr><th>Industry</th><td><input type="text" name="industry" class="regular-text" value="<?php echo esc_attr((string) $brand['industry']); ?>"></td></tr>
			<tr><th>Primary Category</th><td><input type="text" name="primary_category" class="regular-text" value="<?php echo esc_attr((string) $brand['primary_category']); ?>"></td></tr>
			<tr><th>Secondary Category</th><td><input type="text" name="secondary_category" class="regular-text" value="<?php echo esc_attr((string) $brand['secondary_category']); ?>"></td></tr>
			<tr><th>Priority URLs (one per line)</th><td><textarea name="priority_urls" rows="3" class="large-text"><?php echo esc_textarea(implode("\n", (array) $brand['priority_urls'])); ?></textarea></td></tr>
			<tr><th>Tone of Voice</th><td><input type="text" name="tone_of_voice" class="large-text" value="<?php echo esc_attr((string) $brand['tone_of_voice']); ?>"></td></tr>
			<tr><th>Product Data Policy</th><td><textarea name="product_data_policy" rows="2" class="large-text"><?php echo esc_textarea((string) $brand['product_data_policy']); ?></textarea></td></tr>
		</table>
		<?php submit_button('Save Brand Profile'); ?>
	</form>
</div>
