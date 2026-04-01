<?php
/**
 * Plugin Name:       WP-CLI Optimized Import Engine
 * Plugin URI:        https://github.com/yudhisthirnahar/wp-cli-optimized-import-engine
 * Description:       High-performance, memory-safe WP-CLI importer for the `wp-product` CPT. Supports bulk CSV imports and direct DB-table imports with cursor pagination, batched transactions, and idempotent processing.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Yudhisthir Nahar
 * Author URI:        https://github.com/yudhisthirnahar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-cli-optimized-import-engine
 * Domain Path:       /languages
 *
 * @package WP_Product_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPPI_VERSION', '1.0.0' );
define( 'WPPI_FILE', __FILE__ );
define( 'WPPI_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPPI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader for plugin classes inside /includes.
 *
 * Class WP_Product_Post_Type      => includes/class-wp-product-post-type.php
 * Class WP_Product_Importer_Base  => includes/class-wp-product-importer-base.php
 * Class WP_Product_Importer       => includes/class-wp-product-importer.php
 * Class WP_Product_DB_Importer    => includes/class-wp-product-db-importer.php
 * Class WP_Product_CLI_Command    => includes/class-wp-product-cli-command.php
 *
 * @param string $class_name Fully-qualified class name.
 * @return void
 */
function wppi_autoload( string $class_name ): void {
	if ( 0 !== strpos( $class_name, 'WP_Product_' ) ) {
		return;
	}
	$filename = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
	$filepath = WPPI_DIR . 'includes/' . $filename;
	if ( file_exists( $filepath ) ) {
		require_once $filepath;
	}
}
spl_autoload_register( 'wppi_autoload' );

/**
 * Bootstrap: register CPT; register CLI command only in WP-CLI context.
 *
 * @return void
 */
function wppi_init(): void {
	( new WP_Product_Post_Type() )->register();
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'wp-product', WP_Product_CLI_Command::class );
	}
}
add_action( 'plugins_loaded', 'wppi_init' );

/**
 * Activation: flush rewrite rules.
 *
 * @return void
 */
function wppi_activate(): void {
	// 1. Force the registration to happen RIGHT NOW, bypassing 'init'.
	$cpt_manager = new WP_Product_Post_Type();
	$cpt_manager->register_post_type();
	$cpt_manager->register_taxonomies();
	flush_rewrite_rules();
}
register_activation_hook( WPPI_FILE, 'wppi_activate' );

/**
 * Deactivation: clean up rewrite rules.
 *
 * @return void
 */
function wppi_deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( WPPI_FILE, 'wppi_deactivate' );
