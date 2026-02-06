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
