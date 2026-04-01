<?php
/**
 * WP-CLI Commands: wp-product import, wp-product import-table
 *
 * @package WP_Product_Importer
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_Product_CLI_Command
 *
 * Exposes two WP-CLI sub-commands:
 *
 *   wp wp-product import <file>          Import products from a CSV file.
 *   wp wp-product import-table <table>   Import products from a DB table.
 *
 * Both commands share the same flags, the same output format, and the same
 * idempotency / performance guarantees provided by the base importer.
 *
 * @since 1.0.0
 */
class WP_Product_CLI_Command {

	/* -------------------------------------------------------------------
	 * Sub-commands
	 * ----------------------------------------------------------------- */

	/**
	 * Import products from a CSV file into the `wp-product` CPT.
	 *
	 * The import is memory-safe (O(1) per row via PHP Generators), idempotent
	 * (keyed on `_wppi_source_id` post meta), and protected against concurrent
	 * runs via a WordPress transient lock.
	 *
	 * Supported CSV delimiters are auto-detected: comma, tab, pipe, semicolon.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Absolute or relative path to the CSV source file.
	 *
	 * [--batch-size=<n>]
	 * : Number of rows to process per database transaction (default: 50, max: 500).
	 *
	 * [--dry-run]
	 * : Parse and validate every row but do not write to the database.
	 *
	 * [--update]
	 * : When a product with a matching source ID already exists, update it instead of skipping.
	 *
	 * [--porcelain]
	 * : Machine-readable output. Suppresses progress bar and warnings; prints
	 *   comma-separated key=value counters on exit (inserted=N,updated=N,skipped=N,failed=N).
	 *
	 * [--monitor-memory]
	 * : Enable per-batch memory and garbage-collector monitoring. Prints a
	 *   Memory Health Report after the import summary.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wp-product import /var/data/products.csv
	 *   wp wp-product import /var/data/products.csv --batch-size=200 --update
	 *   wp wp-product import /var/data/products.csv --dry-run
	 *   wp wp-product import /var/data/products.csv --porcelain
	 *   wp wp-product import /var/data/products.csv --monitor-memory
	 *
	 * @subcommand import
	 *
	 * @param array $args       Positional arguments. $args[0] = <file>.
	 * @param array $assoc_args Associative (flag) arguments.
	 * @return void
	 */
	public function import( array $args, array $assoc_args ): void {
		$this->raise_memory_limit();

		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please provide a path to the CSV import file.' );
		}

		$file  = $args[0];
		$flags = $this->parse_common_flags( $assoc_args );

		if ( ! $flags['porcelain'] ) {
			WP_CLI::log( sprintf( 'Importing from: %s', $file ) );
			if ( $flags['dry_run'] ) {
				WP_CLI::log( '  [dry-run] No data will be written.' );
			}
		}

		$importer = new WP_Product_Importer(
			$file,
			array(
				'batch_size'     => $flags['batch_size'],
				'dry_run'        => $flags['dry_run'],
				'update'         => $flags['update'],
				'monitor_memory' => $flags['monitor_memory'],
			)
		);

		// Build byte-based progress bar (O(1) filesize stat — no scanning).
		$progress = null;
		if ( ! $flags['porcelain'] ) {
			try {
				$total_bytes = $importer->get_file_size();
				$progress    = WP_CLI\Utils\make_progress_bar(
					$flags['dry_run'] ? 'Dry-run scanning' : 'Importing',
					$total_bytes
				);
			} catch ( RuntimeException $e ) {
				WP_CLI::warning( 'Could not determine file size: ' . $e->getMessage() );
			}
		}

		// Progress callback: advance bar by byte delta since the last tick.
		$last_bytes        = 0;
		$progress_callback = static function ( int $progress_token ) use ( $progress, &$last_bytes ): void {
			if ( $progress ) {
				$delta      = $progress_token - $last_bytes;
				$last_bytes = $progress_token;
				$progress->tick( $delta );
			}
		};

		$warn = $this->make_warn_callback( $flags['porcelain'] );

		try {
			$summary = $importer->run( $progress_callback, $warn );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		if ( $progress ) {
			$progress->finish();
		}

		$this->print_summary( $summary, $flags['dry_run'], $flags['porcelain'] );

		if ( $flags['monitor_memory'] ) {
			$this->print_memory_report( $importer );
		}
	}

	/**
	 * Import products from a custom database table into the `wp-product` CPT.
	 *
	 * The source table must exist in the current WordPress database and must
	 * not be a WordPress core table. Columns follow the same naming convention
	 * as the CSV importer (post_title, post_content, meta_*, tax_*, etc.).
	 *
	 * The import uses cursor-based pagination (WHERE id > $last ORDER BY id)
	 * for O(1) performance per page on large tables. To filter rows, create
	 * a MySQL VIEW on the source table and import from the view instead of
	 * using raw SQL conditions.
	 *
	 * ## OPTIONS
	 *
	 * <table>
	 * : Name of the source table (with or without the WordPress table prefix).
	 *
	 * [--id-column=<col>]
	 * : Table column that uniquely identifies each row. Used as the source_id
	 *   for idempotency and as the cursor for pagination. Must be an integer
	 *   type with an index for best performance. Default: id.
	 *
	 * [--batch-size=<n>]
	 * : Number of rows to process per database transaction (default: 50, max: 500).
	 *
	 * [--dry-run]
	 * : Validate rows but do not write to the database.
	 *
	 * [--update]
	 * : Update existing products instead of skipping them.
	 *
	 * [--porcelain]
	 * : Machine-readable output. Suppresses progress bar and warnings; prints
	 *   comma-separated key=value counters on exit (inserted=N,updated=N,skipped=N,failed=N).
	 *
	 * [--monitor-memory]
	 * : Print a per-batch memory health report after the import.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wp-product import-table legacy_products
	 *   wp wp-product import-table legacy_products --id-column=product_id --update
	 *   wp wp-product import-table legacy_products --batch-size=100 --dry-run
	 *   wp wp-product import-table legacy_products --porcelain
	 *   wp wp-product import-table legacy_products --monitor-memory
	 *
	 * @subcommand import-table
	 *
	 * @param array $args       Positional arguments. $args[0] = <table>.
	 * @param array $assoc_args Associative (flag) arguments.
	 * @return void
	 */
	public function import_table( array $args, array $assoc_args ): void {
		$this->raise_memory_limit();

		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please provide a table name.' );
		}

		$table     = $args[0];
		$flags     = $this->parse_common_flags( $assoc_args );
		$id_column = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'id-column', 'id' );

		if ( ! $flags['porcelain'] ) {
			WP_CLI::log( sprintf( 'Importing from table: %s (id-column: %s)', $table, $id_column ) );
			if ( $flags['dry_run'] ) {
				WP_CLI::log( '  [dry-run] No data will be written.' );
			}
		}

		$importer = new WP_Product_DB_Importer(
			$table,
			array(
				'batch_size'     => $flags['batch_size'],
				'dry_run'        => $flags['dry_run'],
				'update'         => $flags['update'],
				'monitor_memory' => $flags['monitor_memory'],
				'id_column'      => $id_column,
			)
		);

		// validate_source() is called inside run(), but we need get_total_rows()
		// before run() for the progress bar. Call it here; run() will re-validate
		// (cheap SHOW TABLES call) and the result is consistent.
		$total_rows = null;
		$progress   = null;

		if ( ! $flags['porcelain'] ) {
			try {
				// validate_source() must run before get_total_rows() to set the
				// qualified table name. Call run() to trigger it via the normal path.
				// Instead, call a pre-flight method that exposes the row count.
				$total_rows = $this->preflight_row_count( $importer, $flags['porcelain'] );
			} catch ( RuntimeException $e ) {
				WP_CLI::error( $e->getMessage() );
			}

			if ( null === $total_rows ) {
				WP_CLI::log( 'Table is empty, nothing to import.' );
				return;
			}

			$progress = WP_CLI\Utils\make_progress_bar(
				$flags['dry_run'] ? 'Dry-run scanning' : 'Importing',
				$total_rows
			);
		}

		// Progress callback: each token is the 1-based row index; tick by 1.
		$progress_callback = static function ( int $progress_token ) use ( $progress ): void {
			if ( $progress ) {
				$progress->tick( 1 );
			}
		};

		$warn = $this->make_warn_callback( $flags['porcelain'] );

		try {
			$summary = $importer->run( $progress_callback, $warn );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		if ( $progress ) {
			$progress->finish();
		}

		$this->print_summary( $summary, $flags['dry_run'], $flags['porcelain'] );

		if ( $flags['monitor_memory'] ) {
			$this->print_memory_report( $importer );
		}
	}

	/* -------------------------------------------------------------------
	 * Private Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Raise PHP memory limit for large imports (CLI only).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function raise_memory_limit(): void {
		set_time_limit( 0 );
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Blacklisted
		@ini_set( 'memory_limit', '250M' );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
	}

	/**
	 * Parse flags common to both import sub-commands.
	 *
	 * @since 1.2.0
	 *
	 * @param array $assoc_args Raw WP-CLI associative args.
	 * @return array{batch_size:int, dry_run:bool, update:bool, porcelain:bool, monitor_memory:bool}
	 */
	private function parse_common_flags( array $assoc_args ): array {
		$batch_size = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', WP_Product_Importer_Base::DEFAULT_BATCH_SIZE );

		if ( $batch_size < 1 || $batch_size > 500 ) {
			WP_CLI::error( sprintf( '--batch-size must be between 1 and 500 (given: %d).', $batch_size ) );
		}

		return array(
			'batch_size'     => $batch_size,
			'dry_run'        => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ),
			'update'         => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'update', false ),
			'porcelain'      => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ),
			'monitor_memory' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'monitor-memory', false ),
		);
	}

	/**
	 * Return a warning callback that respects porcelain mode.
	 *
	 * In porcelain mode warnings are suppressed so automated pipelines
	 * are not confused by interleaved human-readable text.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $porcelain Whether porcelain mode is active.
	 * @return callable
	 */
	private function make_warn_callback( bool $porcelain ): callable {
		return static function ( string $message ) use ( $porcelain ): void {
			if ( ! $porcelain ) {
				WP_CLI::warning( $message );
			}
		};
	}

	/**
	 * Run the DB importer's validation and return the row count.
	 *
	 * validate_source() must run before get_total_rows() to set the
	 * qualified table name. validate_and_count() does both in one call so
	 * the CLI can build the progress bar before invoking run().
	 *
	 * @since 1.2.0
	 *
	 * @param WP_Product_DB_Importer $importer  The importer instance.
	 * @param bool                   $porcelain Unused; present for signature consistency.
	 * @return int|null Row count, or null if the table is empty.
	 * @throws RuntimeException Bubbled from validate_source() on failure.
	 */
	private function preflight_row_count( WP_Product_DB_Importer $importer, bool $porcelain ): ?int {
		return $importer->validate_and_count();
	}

	/**
	 * Print the import result summary.
	 *
	 * In porcelain mode a single machine-readable line is emitted.
	 * In normal mode a formatted table is printed.
	 *
	 * @since 1.2.0
	 *
	 * @param array $summary   Import result from importer->run().
	 * @param bool  $dry_run   Whether this was a dry-run.
	 * @param bool  $porcelain Whether porcelain mode is active.
	 * @return void
	 */
	private function print_summary( array $summary, bool $dry_run, bool $porcelain ): void {
		if ( $porcelain ) {
			WP_CLI::log(
				sprintf(
					'inserted=%d,updated=%d,skipped=%d,failed=%d',
					$summary['inserted'],
					$summary['updated'],
					$summary['skipped'],
					$summary['failed']
				)
			);
			return;
		}

		$dry_label = $dry_run ? ' (dry-run -- nothing was written)' : '';

		WP_CLI::log( '' );
		WP_CLI::success( sprintf( 'Import complete%s.', $dry_label ) );

		$table_items = array(
			array( 'Metric', 'Count' ),
			array( 'Inserted', number_format( $summary['inserted'] ) ),
			array( 'Updated', number_format( $summary['updated'] ) ),
			array( 'Skipped', number_format( $summary['skipped'] ) ),
			array( 'Failed', number_format( $summary['failed'] ) ),
			array( 'Total rows processed', number_format( $summary['inserted'] + $summary['updated'] + $summary['skipped'] + $summary['failed'] ) ),
		);

		$col_width = 26;
		foreach ( $table_items as $i => $row ) {
			if ( 0 === $i ) {
				WP_CLI::log( sprintf( '  %-' . $col_width . 's %s', $row[0], $row[1] ) );
				WP_CLI::log( '  ' . str_repeat( '-', $col_width + 10 ) );
				continue;
			}
			WP_CLI::log( sprintf( '  %-' . $col_width . 's %s', $row[0], $row[1] ) );
		}

		if ( $summary['failed'] > 0 ) {
			WP_CLI::warning( sprintf( '%d row(s) failed. Re-run with WP_DEBUG=true for details.', $summary['failed'] ) );
		}
	}

	/**
	 * Print a memory health report after import.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_Product_Importer_Base $importer The importer instance.
	 * @return void
	 */
	private function print_memory_report( WP_Product_Importer_Base $importer ): void {
		$report = $importer->get_memory_report();

		if ( empty( $report['summary'] ) ) {
			return;
		}

		$s = $report['summary'];

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B━━━ Memory Health Report ━━━%n' ) );

		if ( $s['is_stable'] ) {
			WP_CLI::log( WP_CLI::colorize( '  %GStatus:%n ✅ Stable (memory growth ≤ 20%)' ) );
		} elseif ( $s['growth_pct'] <= 50.0 ) {
			WP_CLI::log( WP_CLI::colorize( sprintf( '  %%YStatus:%%n ⚠️  Growing (+%.1f%%). Consider lowering --batch-size.', $s['growth_pct'] ) ) );
		} else {
			WP_CLI::log( WP_CLI::colorize( sprintf( '  %%RStatus:%%n 🚨 Critical growth (+%.1f%%). Possible memory leak. Lower --batch-size immediately.', $s['growth_pct'] ) ) );
		}

		WP_CLI::log( '' );

		$limit_display = ( -1 === (int) $s['limit_mb'] ) ? 'Unlimited' : $s['limit_mb'] . ' MB';

		$summary_rows = array(
			array( 'Metric', 'Value' ),
			array( 'PHP memory_limit', $limit_display ),
			array( 'Memory after batch 1', $s['first_mb'] . ' MB' ),
			array( 'Memory after last batch', $s['last_mb'] . ' MB' ),
			array( 'Peak memory (lifetime)', $s['peak_mb'] . ' MB' ),
			array( 'Growth (first → last)', sprintf( '%+.1f%%', $s['growth_pct'] ) ),
			array( 'GC cycles run', number_format( $s['gc_total_runs'] ) ),
			array( 'GC objects freed', number_format( $s['gc_total_freed'] ) ),
		);

		$col_width = 28;
		foreach ( $summary_rows as $i => $row ) {
			if ( 0 === $i ) {
				WP_CLI::log( sprintf( '  %-' . $col_width . 's %s', $row[0], $row[1] ) );
				WP_CLI::log( '  ' . str_repeat( '─', $col_width + 16 ) );
				continue;
			}
			WP_CLI::log( sprintf( '  %-' . $col_width . 's %s', $row[0], $row[1] ) );
		}

		$snapshots = $report['snapshots'];
		if ( count( $snapshots ) > 1 ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%B  Per-batch snapshots:%n' ) );
			WP_CLI::log( sprintf(
				'  %-8s %-14s %-14s %-12s %-10s %-10s',
				'Batch',
				'Current MB',
				'Peak MB',
				'Usage %',
				'GC Runs',
				'GC Freed'
			) );
			WP_CLI::log( '  ' . str_repeat( '─', 72 ) );

			foreach ( $snapshots as $snap ) {
				WP_CLI::log( sprintf(
					'  %-8d %-14s %-14s %-12s %-10d %-10d',
					$snap['batch'],
					$snap['current_mb'],
					$snap['peak_mb'],
					$snap['usage_pct'] . '%',
					$snap['gc_runs'],
					$snap['gc_collected']
				) );
			}
		}

		WP_CLI::log( '' );
	}
}
