<?php

/**
 * Plugin Name: WP Store Import
 * Plugin URI: https://github.com/wp-store/wp-store-import
 * Description: Import data from Velocity Toko & WooCommerce to WP Store
 * Version: 1.0.0
 * Author: Aditya Kristyanto
 * Author URI: https://websweetstudio.com
 * License: GPLv2 or later
 * Text Domain: wp-store-import
 */

if (! defined('ABSPATH')) {
	exit;
}

define('WP_STORE_IMPORT_VERSION', '1.0.0');
define('WP_STORE_IMPORT_PATH', plugin_dir_path(__FILE__));
define('WP_STORE_IMPORT_URL', plugin_dir_url(__FILE__));

// Autoloader
spl_autoload_register(function ($class) {
	$prefix = 'WP_Store_Import\\';
	$base_dir = WP_STORE_IMPORT_PATH . 'src/';

	$len = strlen($prefix);
	if (strncmp($prefix, $class, $len) !== 0) {
		return;
	}

	$relative_class = substr($class, $len);
	$file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

	if (file_exists($file)) {
		require $file;
	}
});

// Initialize Plugin
function wp_store_import_init()
{
	// Check if WP Store is active?
	// For now just init the admin menu
	new \WP_Store_Import\Admin\AdminMenu();
}
add_action('plugins_loaded', 'wp_store_import_init');

function wp_store_import_run()
{
	if (! current_user_can('manage_options')) {
		wp_send_json_error(['message' => 'forbidden']);
	}
	$nonce = isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : '';
	if (! wp_verify_nonce($nonce, 'wp_rest')) {
		wp_send_json_error(['message' => 'invalid_nonce']);
	}
	$source = isset($_POST['source']) ? sanitize_text_field($_POST['source']) : 'velocity';
	$runner = new \WP_Store_Import\Migrator\Runner();
	$results = $runner->run($source);
	wp_send_json_success($results);
}
add_action('wp_ajax_wp_store_import_run', 'wp_store_import_run');

function wp_store_import_inject_settings()
{
	if (! is_admin()) {
		return;
	}
	$page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
	if ($page !== 'wp-store-settings') {
		return;
	}

	$sources = [
		'velocity'    => 'Velocity Toko',
		'woocommerce' => 'WooCommerce',
	];
	$sources = apply_filters('wp_store_import_sources', $sources);
?>
	<div class="wp-store-box-gray">
		<h3 class="wp-store-subtitle">Import Data</h3>
		<p class="wp-store-helper">Migrasi dari Velocity Toko atau WooCommerce ke WP Store.</p>
		<div class="wp-store-mt-4" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
			<select id="wp-store-import-source" class="wp-store-input" style="min-width:220px;">
				<?php foreach ($sources as $value => $label) : ?>
					<option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="wp-store-btn wp-store-btn-secondary" id="wp-store-import-run">
				<span class="dashicons dashicons-download"></span> Import
			</button>
		</div>
		<div class="wp-store-mt-2" style="max-height:300px; overflow:auto;">
			<pre id="wp-store-import-result" style="white-space:pre-wrap;"></pre>
		</div>
	</div>
	<script>
		(function() {
			function initImport() {
				var btn = document.getElementById('wp-store-import-run');
				if (!btn) return;

				var running = false;
				btn.addEventListener('click', function() {
					if (running) return;
					running = true;
					var source = document.getElementById('wp-store-import-source').value || 'velocity';
					var nonceEl = document.getElementById('_wpnonce');
					var nonce = nonceEl ? nonceEl.value : '';
					var resultEl = document.getElementById('wp-store-import-result');
					resultEl.textContent = '';
					btn.disabled = true;
					btn.innerHTML = '<span class="dashicons dashicons-update" style="animation: spin 2s linear infinite;"></span> Memproses...';
					var params = new URLSearchParams();
					params.append('action', 'wp_store_import_run');
					params.append('source', source);
					params.append('_wpnonce', nonce);
					fetch(ajaxurl, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded'
						},
						body: params.toString()
					}).then(function(r) {
						return r.json()
					}).then(function(json) {
						if (json && json.success) {
							resultEl.textContent = JSON.stringify(json.data, null, 2);
						} else {
							var msg = (json && json.data && json.data.message) ? json.data.message : 'Gagal import';
							resultEl.textContent = msg;
						}
					}).catch(function() {
						resultEl.textContent = 'Terjadi kesalahan jaringan.';
					}).finally(function() {
						running = false;
						btn.disabled = false;
						btn.innerHTML = '<span class="dashicons dashicons-download"></span> Import';
					});
				});
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initImport);
			} else {
				initImport();
			}
		})();
	</script>
<?php
}
add_action('wp_store_settings_tools_tab', 'wp_store_import_inject_settings');
