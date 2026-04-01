<?php
/**
 * Custom Post Type: wp-product
 *
 * @package WP_CLI_Optimized_Import_Engine
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_Product_Post_Type
 *
 * Registers the `wp-product` CPT and its taxonomies.
 * No hooks in the constructor so the object can be safely instantiated
 * during the activation hook without side-effects.
 *
 * @since 1.0.0
 */
class WP_Product_Post_Type {

	/**
	 * Post type slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const POST_TYPE = 'wp-product';

	/**
	 * Product-category taxonomy slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TAXONOMY_CATEGORY = 'wp-product-cat';

	/**
	 * Product-tag taxonomy slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TAXONOMY_TAG = 'wp-product-tag';

	/**
	 * Attach WordPress hooks for registration.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ), 0 );
		add_action( 'init', array( $this, 'register_taxonomies' ), 0 );
	}

	/**
	 * Register the `wp-product` post type.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'                  => _x( 'Products', 'post type general name', 'wp-product-importer' ),
					'singular_name'         => _x( 'Product', 'post type singular name', 'wp-product-importer' ),
					'menu_name'             => _x( 'Products', 'admin menu', 'wp-product-importer' ),
					'add_new_item'          => __( 'Add New Product', 'wp-product-importer' ),
					'edit_item'             => __( 'Edit Product', 'wp-product-importer' ),
					'view_item'             => __( 'View Product', 'wp-product-importer' ),
					'all_items'             => __( 'All Products', 'wp-product-importer' ),
					'search_items'          => __( 'Search Products', 'wp-product-importer' ),
					'not_found'             => __( 'No products found.', 'wp-product-importer' ),
					'not_found_in_trash'    => __( 'No products found in Trash.', 'wp-product-importer' ),
					'items_list'            => __( 'Products list', 'wp-product-importer' ),
					'items_list_navigation' => __( 'Products list navigation', 'wp-product-importer' ),
				),
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => true,
				'query_var'          => true,
				'rewrite'            => array(
					'slug'       => 'products',
					'with_front' => false,
				),
				'capability_type'    => 'post',
				'has_archive'        => 'products',
				'hierarchical'       => false,
				'menu_position'      => 20,
				'menu_icon'          => 'dashicons-products',
				'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
			)
		);
	}

	/**
	 * Register product category and tag taxonomies.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_taxonomies(): void {
		register_taxonomy(
			self::TAXONOMY_CATEGORY,
			array( self::POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => _x( 'Product Categories', 'taxonomy general name', 'wp-product-importer' ),
					'singular_name' => _x( 'Product Category', 'taxonomy singular name', 'wp-product-importer' ),
					'edit_item'     => __( 'Edit Category', 'wp-product-importer' ),
					'update_item'   => __( 'Update Category', 'wp-product-importer' ),
					'add_new_item'  => __( 'Add New Category', 'wp-product-importer' ),
					'menu_name'     => __( 'Categories', 'wp-product-importer' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'product-category' ),
			)
		);

		register_taxonomy(
			self::TAXONOMY_TAG,
			array( self::POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => _x( 'Product Tags', 'taxonomy general name', 'wp-product-importer' ),
					'singular_name' => _x( 'Product Tag', 'taxonomy singular name', 'wp-product-importer' ),
					'edit_item'     => __( 'Edit Tag', 'wp-product-importer' ),
					'update_item'   => __( 'Update Tag', 'wp-product-importer' ),
					'add_new_item'  => __( 'Add New Tag', 'wp-product-importer' ),
					'menu_name'     => __( 'Tags', 'wp-product-importer' ),
				),
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'product-tag' ),
			)
		);
	}
}
