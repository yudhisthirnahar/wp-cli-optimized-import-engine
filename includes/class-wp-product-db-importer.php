<?php
/**
 * Database-table importer for WP Product Importer.
 *
 * Concrete implementation of WP_Product_Importer_Base that reads from a
 * custom table in the current WordPress database.
 *
 * The source table must follow the same column-naming convention as the CSV
 * importer (source_id / --id-column, post_title, post_content, post_excerpt,
 * post_status, post_date, meta_*, tax_*).
 *
 * Pagination Strategy
 * -------------------
 * Rows are fetched using cursor-based pagination:
 *
 *   SELECT ... WHERE `id_col` > $last_cursor ORDER BY `id_col` ASC LIMIT N
 *
 * This is O(1) per page because MySQL starts the scan from the indexed cursor
 * value rather than skipping rows.  LIMIT/OFFSET degrades to O(M) on large
 * tables because MySQL must scan and discard M rows per page.
 *
 * The id_column must be an integer type and should have an index (primary
 * key is ideal).  A warning is emitted during validation if a non-integer
 * type is detected.
 *
 * Security / WPCS Notes
 * ----------------------
 * Table names and column names cannot be parameterised via $wpdb->prepare()
 * (%s adds quotes, which breaks MySQL identifier syntax).  The approved WPCS
 * pattern is:
 *
 *  1. Sanitize the name to /^[a-zA-Z0-9_]+$/ before use.
 *  2. Interpolate the sanitised literal into the SQL string.
 *  3. Suppress PreparedSQL.InterpolatedNotPrepared with an inline comment
 *     pointing to the sanitisation step.
 *
 * All scalar values (cursor, limit) continue to use $wpdb->prepare().
 *
 * Usage Examples
 * ---------------
 *   # Basic import (id column defaults to 'id'):
 *   wp wp-product import-table legacy_products --path=/path/to/wordpress
 *
 *   # Map a custom column as the source ID and cursor:
 *   wp wp-product import-table legacy_products --id-column=product_id --path=/path/to/wordpress
 *
 *   # Dry-run to preview what would be inserted/updated without writing:
 *   wp wp-product import-table legacy_products --dry-run --path=/path/to/wordpress
 *
 *   # Update existing products instead of skipping them:
 *   wp wp-product import-table legacy_products --update --path=/path/to/wordpress
 *
 *   # Larger batch size with memory monitoring:
 *   wp wp-product import-table legacy_products --batch-size=100 --monitor-memory --path=/path/to/wordpress
 *
 *   # Machine-readable output for scripting pipelines:
 *   wp wp-product import-table legacy_products --porcelain --path=/path/to/wordpress
 *
 * @package WP_Product_Importer
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_Product_DB_Importer
 *
 * @since 1.2.0
 */
class WP_Product_DB_Importer extends WP_Product_Importer_Base {

	/**
	 * Raw table name as supplied by the caller (before validation).
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private string $table_name;

	/**
	 * Fully-qualified table name (with prefix where applicable).
	 *
	 * Set only after successful validation in validate_source().
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private string $qualified_table = '';

	/**
	 * Table column that maps to the source_id field for idempotency
	 * and acts as the cursor for pagination.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private string $id_column;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param string $table_name Bare table name (with or without WP prefix).
	 * @param array  $options {
	 *     Optional. Import options (see WP_Product_Importer_Base::__construct()).
	 *
	 *     @type string $id_column Table column to use as source_id and cursor. Default 'id'.
	 * }
	 */
	public function __construct( string $table_name, array $options = array() ) {
		parent::__construct( $options );
		$this->table_name = $table_name;
		$this->id_column  = sanitize_key( $options['id_column'] ?? 'id' );
		if ( '' === $this->id_column ) {
			$this->id_column = 'id';
		}
	}

	/* -------------------------------------------------------------------
	 * Public API
	 * ----------------------------------------------------------------- */

	/**
	 * Run source validation and return the row count in one call.
	 *
	 * Used by the CLI to obtain the progress bar total before calling run().
	 * validate_source() must execute first to set $this->qualified_table;
	 * this method does both so callers don't need access to the protected method.
	 *
	 * @since 1.2.0
	 *
	 * @return int|null Row count, or null when the table is empty.
	 * @throws RuntimeException Bubbled from validate_source() on failure.
	 */
	public function validate_and_count(): ?int {
		$this->validate_source();
		return $this->get_total_rows();
	}

	/**
	 * Return total importable rows for the row-count progress bar.
	 *
	 * Returns null when the table is empty so the CLI can skip the import
	 * gracefully instead of showing a zero-total progress bar.
	 *
	 * Must be called after validate_source() has set $this->qualified_table.
	 *
	 * @since 1.2.0
	 *
	 * @return int|null Row count, or null when the table is empty.
	 */
	public function get_total_rows(): ?int {
		global $wpdb;

		$tbl = $this->qualified_table;

		// $tbl is sanitised to [a-zA-Z0-9_] in validate_source() Step 1.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tbl}`" );

		return $count > 0 ? $count : null;
	}

	/* -------------------------------------------------------------------
	 * Source Validation (WP_Product_Importer_Base contract)
	 * ----------------------------------------------------------------- */

	/**
	 * Validate the source table in five ordered steps.
	 *
	 * Step 1 – Sanitize table name to [a-zA-Z0-9_].
	 * Step 2 – Reject WordPress core tables.
	 * Step 3 – Confirm the table exists in the current database.
	 * Step 4 – Confirm required columns are present.
	 * Step 5 – Warn if id_column is not an integer type.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 * @throws RuntimeException On any validation failure.
	 */
	protected function validate_source(): void {
		global $wpdb;

		// ------------------------------------------------------------------
		// Step 1: Sanitize the table name.
		//
		// Only [a-zA-Z0-9_] are allowed. This sanitized value is what gets
		// interpolated into all subsequent SQL strings, making the
		// InterpolatedNotPrepared suppressions below safe.
		// ------------------------------------------------------------------
		$sanitized = preg_replace( '/[^a-zA-Z0-9_]/', '', $this->table_name );

		if ( '' === $sanitized || $sanitized !== $this->table_name ) {
			throw new RuntimeException(
				sprintf(
					'Table name "%s" contains invalid characters. Only letters, digits, and underscores are allowed.',
					$this->table_name
				)
			);
		}

		// ------------------------------------------------------------------
		// Step 2: Reject WordPress core tables.
		//
		// Build the blocked list from $wpdb properties so it stays accurate
		// across WP versions. Both bare names and prefixed names are blocked.
		// ------------------------------------------------------------------
		$core_table_groups = array_filter(
			array(
				$wpdb->tables ?? array(),
				$wpdb->old_tables ?? array(),
				$wpdb->global_tables ?? array(),
				$wpdb->ms_global_tables ?? array(),
				$wpdb->old_ms_global_tables ?? array(),
			)
		);

		$core_bare       = array_merge( ...$core_table_groups );
		$core_prefixed   = array_map( static fn( $t ) => $wpdb->prefix . $t, $core_bare );
		$all_blocked     = array_unique( array_merge( $core_bare, $core_prefixed ) );

		if ( in_array( $sanitized, $all_blocked, true ) ) {
			throw new RuntimeException(
				sprintf(
					'Table "%s" is a WordPress core table and cannot be used as an import source.',
					$sanitized
				)
			);
		}

		// ------------------------------------------------------------------
		// Step 3: Confirm the table exists in the current database.
		//
		// Try with the WP prefix first, then the bare name. The table name
		// value is passed through $wpdb->prepare() here (it is a LIKE value,
		// not an identifier).
		// ------------------------------------------------------------------
		$prefixed = $wpdb->prefix . $sanitized;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found_prefixed = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefixed ) );

		if ( $prefixed === $found_prefixed ) {
			$this->qualified_table = $prefixed;
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found_bare = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sanitized ) );

			if ( $sanitized !== $found_bare ) {
				throw new RuntimeException(
					sprintf(
						'Table "%s" does not exist in the current database (also tried "%s").',
						$sanitized,
						$prefixed
					)
				);
			}

			$this->qualified_table = $sanitized;
		}

		$tbl = $this->qualified_table;

		// ------------------------------------------------------------------
		// Step 4: Confirm required columns are present.
		//
		// $tbl passed sanitisation in Step 1 — safe to interpolate.
		// phpcs:ignore covers the PreparedSQL.InterpolatedNotPrepared sniff.
		// ------------------------------------------------------------------

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column_rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$tbl}`", ARRAY_A );
		$columns     = array_column( (array) $column_rows, 'Field' );
		$columns     = array_map( 'strtolower', $columns );

		$required = array( 'post_title', $this->id_column );
		$missing  = array_diff( $required, $columns );

		if ( ! empty( $missing ) ) {
			throw new RuntimeException(
				sprintf(
					'Table "%s" is missing required column(s): %s. Use --id-column to map a different column as source_id.',
					$tbl,
					implode( ', ', $missing )
				)
			);
		}

		// ------------------------------------------------------------------
		// Step 5: Warn if id_column is not an integer type.
		//
		// Cursor pagination uses WHERE id > %d which casts the cursor as an
		// integer. Non-integer columns still work but comparisons may be
		// inconsistent on columns with varchar / UUID values.
		// ------------------------------------------------------------------
		$id_col_def = array_filter(
			(array) $column_rows,
			fn( $col ) => strtolower( $col['Field'] ) === $this->id_column
		);
		$id_col_def = reset( $id_col_def );
		$col_type   = strtolower( (string) ( $id_col_def['Type'] ?? '' ) );

		$is_int = (bool) preg_match( '/^(int|tinyint|smallint|mediumint|bigint)/', $col_type );

		if ( ! $is_int && defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::warning(
				sprintf(
					'Column "%s" has type "%s", not an integer. Cursor pagination uses integer comparison; ' .
					'results may be inconsistent for non-integer types. Consider using --id-column with an integer column.',
					$this->id_column,
					$col_type
				)
			);
		}
	}

	/* -------------------------------------------------------------------
	 * Cursor-Pagination Generator (WP_Product_Importer_Base contract)
	 * ----------------------------------------------------------------- */

	/**
	 * Stream the source table one row at a time using cursor-based pagination.
	 *
	 * Each page is fetched with:
	 *
	 *   WHERE `id_col` > $cursor ORDER BY `id_col` ASC LIMIT $batch_size
	 *
	 * The Generator key is a 1-based row counter used as the progress token.
	 * Only post_title and post_content are fetched alongside the id column;
	 * meta_* and tax_* columns are not read from the source table.
	 *
	 * Memory: O(batch_size) — one page of raw rows in $wpdb->get_results()
	 * plus the current yielded row.
	 *
	 * @since 1.2.0
	 *
	 * @return Generator<int, array<string, string>>
	 */
	protected function stream_rows(): Generator {
		global $wpdb;

		$tbl      = $this->qualified_table; // sanitised to [a-zA-Z0-9_] in validate_source() Step 1.
		$id_col   = $this->id_column;       // sanitised via sanitize_key().
		$batch_sz = (int) $this->options['batch_size'];
		$cursor  = 0; // Cursor starts before the first row.
		$counter = 0; // 1-based absolute row counter (progress token).

		do {
			// Only fetch the three columns we need.
			// $tbl and $id_col are sanitised literals — InterpolatedNotPrepared
			// suppression is safe. Cursor and limit go through prepare().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT `{$id_col}` AS `source_id`, `post_title`, `post_content`
					 FROM   `{$tbl}`
					 WHERE  `{$id_col}` > %d
					 ORDER  BY `{$id_col}` ASC
					 LIMIT  %d",
					$cursor,
					$batch_sz
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				return; // Generator exhausted.
			}

			foreach ( $rows as $raw_row ) {
				++$counter;
				yield $counter => array_change_key_case( (array) $raw_row, CASE_LOWER );
			}

			// Advance cursor using the aliased source_id column (always present).
			$last_row = end( $rows );
			$cursor   = (int) $last_row['source_id'];

		} while ( count( $rows ) === $batch_sz );
		// A partial page means we have reached the end of the table.
	}
}
