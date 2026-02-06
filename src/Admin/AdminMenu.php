<?php

namespace WP_Store_Import\Admin;

class AdminMenu {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=store_product', // Parent slug (assuming wp-store uses store_product)
			'Import from Velocity',
			'Import Velocity',
			'manage_options',
			'wp-store-import',
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		?>
        <div class="wrap">
            <h1>WP Store Import</h1>
            <form method="post" action="">
                <?php wp_nonce_field( 'wp_store_import_action', 'wp_store_import_nonce' ); ?>
                <input type="hidden" name="action" value="wp_store_run_import">
                <p>
                    <label for="wp_store_import_source">Sumber Data</label><br>
                    <select id="wp_store_import_source" name="source">
                        <option value="velocity">Velocity Toko</option>
                        <option value="woocommerce">WooCommerce</option>
                    </select>
                </p>
                <button type="submit" class="button button-primary">Mulai Import</button>
            </form>
            <div id="import-results" style="margin-top: 20px;"></div>
        </div>
		<?php
        
        $this->handle_import();
	}

    public function handle_import() {
        if ( isset( $_POST['action'] ) && $_POST['action'] === 'wp_store_run_import' ) {
            if ( ! isset( $_POST['wp_store_import_nonce'] ) || ! wp_verify_nonce( $_POST['wp_store_import_nonce'], 'wp_store_import_action' ) ) {
                echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
                return;
            }

            // Prevent timeout
            set_time_limit(0);

            // Trigger migration
            $runner = new \WP_Store_Import\Migrator\Runner();
            $source = isset($_POST['source']) ? sanitize_text_field($_POST['source']) : 'velocity';
            $results = $runner->run($source);

            echo '<div class="notice notice-success"><p>Import completed!</p>';
            echo '<pre>' . print_r( $results, true ) . '</pre>';
            echo '</div>';
        }
    }
}
