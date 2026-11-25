<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap htd-wrap">
	<h1>WooCommerce Products &amp; MASTER PRODUCT Protection</h1>
	<?php if (empty($available)) : ?>
		<div class="notice notice-warning"><p>WooCommerce is not active in this environment. Product Engine is in safe standby mode.</p></div>
	<?php else : ?>
		<table class="widefat striped">
			<thead><tr><th>ID</th><th>SKU</th><th>Name</th><th>Type</th><th>Price (Protected)</th><th>Stock</th><th>Propose Content</th></tr></thead>
			<tbody>
			<?php if (empty($products)) : ?>
				<tr><td colspan="7">No products found.</td></tr>
			<?php else : foreach ($products as $p) : ?>
				<tr>
					<td><?php echo esc_html((string) $p['id']); ?></td>
					<td><code><?php echo esc_html((string) $p['sku']); ?></code></td>
					<td><strong><?php echo esc_html((string) $p['name']); ?></strong></td>
					<td><?php echo esc_html((string) $p['type']); ?></td>
					<td><?php echo esc_html((string) $p['regular_price']); ?></td>
					<td><?php echo esc_html((string) $p['stock_status']); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
							<?php wp_nonce_field('htd_ai_seo_product_content'); ?>
							<input type="hidden" name="action" value="htd_ai_seo_product_content">
							<input type="hidden" name="product_id" value="<?php echo esc_attr((string) $p['id']); ?>">
							<input type="text" name="short_description" placeholder="Short description" class="regular-text"><br>
							<textarea name="description" rows="2" placeholder="Detailed description" class="regular-text" style="margin-top:4px;"></textarea><br>
							<button type="submit" class="button button-small">Propose Content</button>
						</form>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
