<?php
/**
 * Abstract base class for WP Product importers.
 *
 * Contains all batch-processing, hook-management, memory-monitoring, and
 * post insert/update logic shared between the CSV importer and the DB-table
 * importer.  Concrete subclasses supply three things only:
 *
 *  - validate_source()  Pre-flight check (throws RuntimeException on failure).
 *  - stream_rows()      Generator that yields progress_token => row_array.
 *  - get_total_rows()   Row count for the progress bar (null = use byte bar).
 *
 * Streaming Architecture (PHP Generators)
 * ----------------------------------------
 * stream_rows() returns a Generator immediately — the source is not opened
 * until process_stream() drives it.  process_stream() accumulates rows into
 * fixed-size batches, flushes each batch inside a DB transaction, then
 * discards the batch array (freeing memory) before requesting the next row.
 *
 * Peak RAM = O(batch_size rows), never O(total rows).
 *
 * Performance tuning applied during import:
 *  - WP_IMPORTING constant stops WP ping-backs, slow meta checks, etc.
 *  - Post revisions are disabled (wp_save_post_revision hook removed).
 *  - wp_suspend_cache_addition() prevents object-cache bloat.
 *  - wp_defer_term_counting() batches recount to the end.
 *  - DB transactions reduce per-row disk-sync overhead.
 *  - Third-party post/meta/term hooks are suspended for the import duration.
 *  - gc_collect_cycles() + cache flush run between batches.
 *  - MySQL connection is pinged before each batch to survive wait_timeout.
 *
 * Column Mapping Convention (shared by all subclasses)
 * ------------------------------------------------------
 * Core    : source_id (required), post_title (required), post_content,
 *           post_excerpt, post_status (default: publish), post_date
 * Meta    : any column prefixed meta_ is stored as _{key} post meta
 * Taxonomy: any column prefixed tax_ triggers taxonomy term assignment
 *
 * @package WP_CLI_Optimized_Import_Engine
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Class WP_Product_Importer_Base
 *
 * @since 1.2.0
 */
abstract class WP_Product_Importer_Base {

	/**
	 * Meta key for external source ID (idempotency).
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const SOURCE_ID_META_KEY = '_wppi_source_id';

	/**
	 * Transient key used as concurrency mutex.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const LOCK_TRANSIENT = 'wppi_import_lock';

	/**
	 * Default records per batch.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 50;

	/**
	 * PHP memory fraction at which a warning is emitted.
	 *
	 * @since 1.2.0
	 * @var float
	 */
	const MEMORY_WARNING_THRESHOLD = 0.80;

	/**
	 * Import options.
	 *
	 * @since 1.2.0
	 * @var array{batch_size:int, dry_run:bool, update:bool, monitor_memory:bool}
	 */
	protected array $options;

	/**
	 * Result counters.
	 *
	 * @since 1.2.0
	 * @var array{inserted:int, updated:int, skipped:int, failed:int}
	 */
	protected array $counts = array(
		'inserted' => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'failed'   => 0,
	);

	/**
	 * Per-row errors keyed by 1-based row number.
	 *
	 * @since 1.2.0
	 * @var array<int, string>
	 */
	protected array $errors = array();

	/**
	 * Collected memory snapshots, one per batch.
	 *
	 * @since 1.2.0
	 * @var array<int, array<string, mixed>>
	 */
	protected array $memory_snapshots = array();

	/**
	 * Running batch counter (1-based).
	 *
	 * @since 1.2.0
	 * @var int
	 */
	protected int $batch_count = 0;

	/**
	 * Backed-up WP_Hook objects, keyed by action name.
	 *
	 * @since 1.2.0
	 * @var array<string, WP_Hook>
	 */
	protected array $backed_up_filters = array();

	/**
	 * Snapshot of $wpdb->show_errors before import mode is enabled.
	 *
	 * @since 1.2.0
	 * @var bool
	 */
	protected bool $wpdb_show_errors_backup = false;

	/* -------------------------------------------------------------------
	 * Constructor
	 * ----------------------------------------------------------------- */

	/**
	 * Initialise shared import options.
	 *
	 * Subclasses must call parent::__construct() and may extend $options
	 * with source-specific keys before calling parent.
	 *
	 * @since 1.2.0
	 *
	 * @param array $options {
	 *     Optional. Import options.
	 *
	 *     @type int  $batch_size      Records per batch (1–500). Default 50.
	 *     @type bool $dry_run         Validate only; no DB writes. Default false.
	 *     @type bool $update          Update existing posts. Default false.
	 *     @type bool $monitor_memory  Collect per-batch memory/GC snapshots. Default false.
	 * }
	 */
	public function __construct( array $options = array() ) {
		$this->options = wp_parse_args(
			$options,
			array(
				'batch_size'     => self::DEFAULT_BATCH_SIZE,
				'dry_run'        => false,
				'update'         => false,
				'monitor_memory' => false,
			)
		);

		$this->options['batch_size'] = max( 1, min( 500, (int) $this->options['batch_size'] ) );
	}

	/* -------------------------------------------------------------------
	 * Abstract Interface
	 * ----------------------------------------------------------------- */

	/**
	 * Perform source-specific pre-flight validation.
	 *
	 * Called at the top of run() before the lock is acquired.
	 * Implementations should throw RuntimeException with a human-readable
	 * message if the source cannot be used.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 * @throws RuntimeException On validation failure.
	 */
	abstract protected function validate_source(): void;

	/**
	 * Return a Generator that yields rows for processing.
	 *
	 * The Generator key is an opaque int forwarded to the progress callback
	 * as the "progress token".  For CSV sources the token is the ftell()
	 * byte position; for DB sources it is the 1-based row counter.
	 *
	 * Each yielded value must be array<string, string> following the shared
	 * column-naming convention (source_id, post_title, meta_*, tax_*).
	 *
	 * @since 1.2.0
	 *
	 * @return Generator<int, array<string, string>>
	 * @throws RuntimeException On unrecoverable source errors.
	 */
	abstract protected function stream_rows(): Generator;

	/**
	 * Return the total number of importable rows for the progress bar.
	 *
	 * Return null if an accurate count is unavailable (e.g. CSV sources
	 * use a byte-based bar instead and return null here).
	 *
	 * @since 1.2.0
	 *
	 * @return int|null Row count, or null when not applicable.
	 */
	abstract public function get_total_rows(): ?int;

	/* -------------------------------------------------------------------
	 * Public API
	 * ----------------------------------------------------------------- */

	/**
	 * Execute the import.
	 *
	 * Execution flow:
	 *  1. validate_source()   — source-specific pre-flight check.
	 *  2. acquire_lock()      — transient mutex; throws if already locked.
	 *  3. stream_rows()       — returns Generator (source NOT yet opened).
	 *  4. process_stream()    — drives Generator in fixed-size batches:
	 *       foreach row → batch[] → when full: flush_batch() DB writes
	 *                            → cleanup_between_batches()
	 *                            → check_db_connection() MySQL ping
	 *  5. release_lock()      — always executes via finally block.
	 *  6. get_summary()       — return counts + per-row error log.
	 *
	 * @since 1.2.0
	 *
	 * @param callable|null $progress_callback Called per row with progress token.
	 *                                         Signature: function( int $progress_token ): void.
	 * @param callable|null $warning_callback  Non-fatal warnings. Signature: function( string ): void.
	 * @return array{inserted:int, updated:int, skipped:int, failed:int, errors:array<int,string>}
	 * @throws RuntimeException When the lock is held or the source is invalid.
	 */
	public function run( ?callable $progress_callback = null, ?callable $warning_callback = null ): array {
		$this->validate_source();
		$this->acquire_lock();

		try {
			$stream = $this->stream_rows();
			$this->process_stream( $stream, $progress_callback, $warning_callback );
		} finally {
			$this->release_lock();
		}

		return $this->get_summary();
	}

	/**
	 * Return a memory report with all snapshots and a summary.
	 *
	 * @since 1.2.0
	 *
	 * @return array{ snapshots: array, summary: array<string, mixed> }
	 */
	public function get_memory_report(): array {
		$snapshots = $this->memory_snapshots;

		if ( empty( $snapshots ) ) {
			return array(
				'snapshots' => array(),
				'summary'   => array(),
			);
		}

		$first    = $snapshots[0];
		$last     = end( $snapshots );
		$peak_mb  = max( array_column( $snapshots, 'peak_mb' ) );
		$gc_runs  = max( array_column( $snapshots, 'gc_runs' ) );
		$gc_freed = max( array_column( $snapshots, 'gc_collected' ) );

		$growth_pct = ( $first['current_mb'] > 0 )
			? round( ( ( $last['current_mb'] - $first['current_mb'] ) / $first['current_mb'] ) * 100, 1 )
			: 0.0;

		return array(
			'snapshots' => $snapshots,
			'summary'   => array(
				'total_batches'  => count( $snapshots ),
				'first_mb'       => $first['current_mb'],
				'last_mb'        => $last['current_mb'],
				'peak_mb'        => $peak_mb,
				'limit_mb'       => $first['limit_mb'],
				'growth_pct'     => $growth_pct,
				'gc_total_runs'  => $gc_runs,
				'gc_total_freed' => $gc_freed,
				'is_stable'      => $growth_pct <= 20.0,
			),
		);
	}

	/* -------------------------------------------------------------------
	 * Batch Processing
	 * ----------------------------------------------------------------- */

	/**
	 * Consume the Generator and process rows in fixed-size batches.
	 *
	 * Wraps each batch in a database transaction for atomicity and reduced
	 * disk I/O. Defers term counting until processing is complete.
	 *
	 * The progress token yielded by stream_rows() is captured and forwarded
	 * to the progress callback unchanged. For CSV sources the token is a
	 * byte position; for DB sources it is a 1-based row index.
	 *
	 * @since 1.2.0
	 *
	 * @param Generator     $stream            Row generator from stream_rows().
	 * @param callable|null $progress_callback Called per row with progress token.
	 * @param callable|null $warning_callback  Called with warning string.
	 * @return void
	 */
	protected function process_stream(
		Generator $stream,
		?callable $progress_callback,
		?callable $warning_callback
	): void {
		global $wpdb;

		$this->enable_import_mode();

		try {
			$batch_size   = $this->options['batch_size'];
			$memory_limit = $this->get_memory_limit_bytes();
			$batch        = array();
			$row_number   = 1;

			foreach ( $stream as $progress_token => $row ) {
				$batch[] = array(
					'row'            => $row,
					'num'            => $row_number,
					'progress_token' => $progress_token,
				);

				if ( count( $batch ) >= $batch_size ) {
					if ( $this->options['dry_run'] ) {
						$this->flush_batch( $batch, $progress_callback );
					} else {
						try {
							$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
							$this->flush_batch( $batch, $progress_callback );
							$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						} catch ( \Throwable $e ) {
							$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
							throw $e;
						}
					}

					$batch = array();
					++$this->batch_count;
					$this->cleanup_between_batches( $memory_limit, $warning_callback );
					if ( $this->options['monitor_memory'] ) {
						$this->log_memory_snapshot( $memory_limit );
					}
					$this->check_db_connection( $warning_callback );
				}

				++$row_number;
			}

			if ( ! empty( $batch ) ) {
				if ( $this->options['dry_run'] ) {
					$this->flush_batch( $batch, $progress_callback );
				} else {
					try {
						$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$this->flush_batch( $batch, $progress_callback );
						$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					} catch ( \Throwable $e ) {
						$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						throw $e;
					}
				}

				++$this->batch_count;
				$this->cleanup_between_batches( $memory_limit, $warning_callback );
				if ( $this->options['monitor_memory'] ) {
					$this->log_memory_snapshot( $memory_limit );
				}
			}
		} finally {
			$this->disable_import_mode();
		}
	}

	/**
	 * Process all rows in the current batch.
	 *
	 * @since 1.2.0
	 *
	 * @param array<int, array{row:array<string,string>, num:int, progress_token:int}> $batch            Rows.
	 * @param callable|null                                                            $progress_callback Called per row with progress token.
	 * @return void
	 */
	protected function flush_batch( array $batch, ?callable $progress_callback ): void {
		// Collect all source_ids in this batch and resolve existing post IDs
		// in a single query instead of one query per row (eliminates N+1).
		$source_ids   = array_filter(
			array_map( static fn( $item ) => trim( $item['row']['source_id'] ?? '' ), $batch )
		);
		$existing_map = $this->batch_get_existing_post_ids( array_values( $source_ids ) );

		foreach ( $batch as $item ) {
			$this->process_single_row( $item['row'], $item['num'], $existing_map );
			if ( is_callable( $progress_callback ) ) {
				call_user_func( $progress_callback, $item['progress_token'] );
			}
		}
	}

	/**
	 * Resolve multiple source IDs to existing post IDs in one query.
	 *
	 * Returns a map of [ source_id => post_id ] for source IDs that already
	 * exist in the database. Missing IDs are absent from the map (caller
	 * treats absence as 0 / not found).
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $source_ids Source IDs from the current batch.
	 * @return array<string, int>  Map of source_id => post_id.
	 */
	protected function batch_get_existing_post_ids( array $source_ids ): array {
		if ( empty( $source_ids ) ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $source_ids ), '%s' ) );
		$args         = array_merge(
			array( self::SOURCE_ID_META_KEY ),
			$source_ids,
			array( WP_Product_Post_Type::POST_TYPE )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS source_id, pm.post_id
				 FROM   {$wpdb->postmeta} pm
				 INNER  JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE  pm.meta_key   = %s
				   AND  pm.meta_value IN ({$placeholders})
				   AND  p.post_type   = %s",
				...$args
			),
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row['source_id'] ] = (int) $row['post_id'];
		}
		return $map;
	}

	/**
	 * Release WP/PHP memory between batches.
	 *
	 * @since 1.2.0
	 *
	 * @param int           $memory_limit     PHP memory_limit in bytes.
	 * @param callable|null $warning_callback Emitted if usage exceeds threshold.
	 * @return void
	 */
	protected function cleanup_between_batches( int $memory_limit, ?callable $warning_callback ): void {
		global $wpdb;

		if ( ! empty( $wpdb->queries ) ) {
			$wpdb->queries = array();
		}
		$wpdb->flush();

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			foreach ( array( 'posts', 'post_meta', 'terms', 'term_meta', 'term_taxonomy' ) as $group ) {
				wp_cache_flush_group( $group );
			}
		} else {
			wp_cache_flush();
		}

		gc_collect_cycles();

		if ( $memory_limit > 0 && is_callable( $warning_callback ) ) {
			$used = memory_get_usage( true );
			if ( $used > ( $memory_limit * self::MEMORY_WARNING_THRESHOLD ) ) {
				call_user_func(
					$warning_callback,
					sprintf(
						'Memory at %.1f%% of limit (%s / %s). Lower --batch-size if this persists.',
						( $used / $memory_limit ) * 100,
						size_format( $used ),
						size_format( $memory_limit )
					)
				);
			}
		}
	}

	/**
	 * Verify the MySQL connection is alive; reconnect if it dropped.
	 *
	 * @since 1.2.0
	 *
	 * @param callable|null $warning_callback Emitted if a reconnect was needed.
	 * @return void
	 */
	protected function check_db_connection( ?callable $warning_callback = null ): void {
		global $wpdb;

		if ( ! method_exists( $wpdb, 'check_connection' ) ) {
			return;
		}

		if ( ! $wpdb->check_connection( false ) ) {
			if ( is_callable( $warning_callback ) ) {
				call_user_func(
					$warning_callback,
					'MySQL connection was lost (wait_timeout?). WordPress reconnected successfully. ' .
					'If this recurs, lower --batch-size or increase MySQL wait_timeout.'
				);
			}
		}
	}

	/* -------------------------------------------------------------------
	 * Single-row Processing
	 * ----------------------------------------------------------------- */

	/**
	 * Validate and persist one import row.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, string> $row          Row data.
	 * @param int                   $row_number   1-based index.
	 * @param array<string, int>    $existing_map Pre-fetched source_id => post_id map.
	 * @return void
	 */
	protected function process_single_row( array $row, int $row_number, array $existing_map = array() ): void {
		$source_id  = trim( $row['source_id'] ?? '' );
		$post_title = trim( $row['post_title'] ?? '' );

		if ( '' === $source_id ) {
			$this->log_error( $row_number, 'Missing required column: source_id' );
			return;
		}
		if ( '' === $post_title ) {
			$this->log_error( $row_number, sprintf( 'source_id "%s": Missing required column: post_title', $source_id ) );
			return;
		}

		$existing_id = $existing_map[ $source_id ] ?? 0;

		if ( $existing_id > 0 ) {
			if ( ! $this->options['update'] ) {
				++$this->counts['skipped'];
				return;
			}
			$this->update_product( $existing_id, $row );
			++$this->counts['updated'];
			return;
		}

		if ( $this->options['dry_run'] ) {
			++$this->counts['inserted'];
			return;
		}

		$post_id = $this->insert_product( $row );

		if ( is_wp_error( $post_id ) ) {
			$this->log_error( $row_number, sprintf( 'source_id "%s": %s', $source_id, $post_id->get_error_message() ) );
			return;
		}

		update_post_meta( $post_id, self::SOURCE_ID_META_KEY, sanitize_text_field( $source_id ) );
		$this->assign_taxonomies( $post_id, $row );

		++$this->counts['inserted'];
	}

	/* -------------------------------------------------------------------
	 * Database Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Build sanitised post data array from a row.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, string> $row Raw row.
	 * @return array<string, mixed>
	 */
	protected function build_post_data( array $row ): array {
		$allowed = array( 'publish', 'draft', 'pending', 'private', 'future' );
		$status  = in_array( $row['post_status'] ?? '', $allowed, true ) ? $row['post_status'] : 'publish';

		$data = array(
			'post_type'    => WP_Product_Post_Type::POST_TYPE,
			'post_status'  => $status,
			'post_title'   => sanitize_text_field( $row['post_title'] ?? '' ),
			'post_content' => wp_kses_post( $row['post_content'] ?? '' ),
			'post_excerpt' => sanitize_textarea_field( $row['post_excerpt'] ?? '' ),
		);

		if ( ! empty( $row['post_date'] ) ) {
			$date = date_create( $row['post_date'] );
			if ( false !== $date ) {
				$data['post_date']     = $date->format( 'Y-m-d H:i:s' );
				$data['post_date_gmt'] = get_gmt_from_date( $data['post_date'] );
			}
		}

		return $data;
	}

	/**
	 * Insert a new wp-product post and its meta.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, string> $row Raw row.
	 * @return int|\WP_Error
	 */
	protected function insert_product( array $row ): int|\WP_Error {
		$post_id = wp_insert_post( $this->build_post_data( $row ), true );
		if ( ! is_wp_error( $post_id ) ) {
			$this->save_meta_fields( $post_id, $row, true );
		}
		return $post_id;
	}

	/**
	 * Update an existing wp-product post and its meta.
	 *
	 * @since 1.2.0
	 *
	 * @param int                   $post_id Existing post ID.
	 * @param array<string, string> $row     Raw row.
	 * @return void
	 */
	protected function update_product( int $post_id, array $row ): void {
		if ( $this->options['dry_run'] ) {
			return;
		}
		$data       = $this->build_post_data( $row );
		$data['ID'] = $post_id;
		wp_update_post( $data );
		$this->save_meta_fields( $post_id, $row );
		$this->assign_taxonomies( $post_id, $row );
	}

	/**
	 * Persist all meta_* columns as private post meta.
	 *
	 * For new posts ($is_new = true) a direct INSERT is used instead of
	 * update_post_meta() to avoid the redundant SELECT check — the meta
	 * is guaranteed not to exist yet.
	 *
	 * @since 1.2.0
	 *
	 * @param int                   $post_id Target post ID.
	 * @param array<string, string> $row     Raw row.
	 * @param bool                  $is_new  True when the post was just inserted.
	 * @return void
	 */
	protected function save_meta_fields( int $post_id, array $row, bool $is_new = false ): void {
		global $wpdb;

		foreach ( $row as $column => $value ) {
			if ( 0 !== strpos( $column, 'meta_' ) ) {
				continue;
			}
			$meta_key   = '_' . substr( $column, 5 );
			$meta_value = sanitize_text_field( $value );

			if ( $is_new ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$wpdb->postmeta,
					array(
						'post_id'    => $post_id,
						'meta_key'   => $meta_key,
						'meta_value' => $meta_value,
					)
				);
			} else {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}
		}
	}

	/**
	 * Assign taxonomy terms from all tax_* columns.
	 *
	 * @since 1.2.0
	 *
	 * @param int                   $post_id Target post ID.
	 * @param array<string, string> $row     Raw row.
	 * @return void
	 */
	protected function assign_taxonomies( int $post_id, array $row ): void {
		foreach ( $row as $column => $value ) {
			if ( 0 !== strpos( $column, 'tax_' ) || '' === trim( $value ) ) {
				continue;
			}
			$taxonomy = substr( $column, 4 );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			wp_set_object_terms( $post_id, array_filter( array_map( 'trim', explode( ',', $value ) ) ), $taxonomy );
		}
	}

	/* -------------------------------------------------------------------
	 * Lock Management
	 * ----------------------------------------------------------------- */

	/**
	 * Acquire transient-based concurrency lock.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 * @throws RuntimeException If already locked.
	 */
	protected function acquire_lock(): void {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			throw new RuntimeException(
				'Another import is already running. ' .
				'Force-clear with: wp transient delete ' . self::LOCK_TRANSIENT
			);
		}
		set_transient( self::LOCK_TRANSIENT, true, HOUR_IN_SECONDS );
	}

	/**
	 * Release the concurrency lock.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	protected function release_lock(): void {
		delete_transient( self::LOCK_TRANSIENT );
	}

	/* -------------------------------------------------------------------
	 * Import Mode (Performance Tuning)
	 * ----------------------------------------------------------------- */

	/**
	 * Enable bulk-import performance optimisations.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	protected function enable_import_mode(): void {
		global $wpdb;

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		// Suppress DB error output during bulk import to avoid noise from
		// transient deadlocks or duplicate-key warnings.
		$this->wpdb_show_errors_backup = (bool) $wpdb->show_errors;
		$wpdb->suppress_errors( true );

		wp_suspend_cache_addition( true );
		wp_defer_term_counting( true );

		remove_action( 'post_updated', 'wp_save_post_revision' );
		remove_action( 'do_pings', 'do_all_pings', 10 );
		remove_action( 'post_updated', 'wp_check_for_changed_slugs', 12 );

		$this->suspend_hooks();
	}

	/**
	 * Restore normal WordPress behaviour after import.
	 *
	 * Called in a finally block so hooks are always restored even if the
	 * import throws an exception.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	protected function disable_import_mode(): void {
		global $wpdb;

		$this->restore_hooks();

		wp_defer_term_counting( false );
		wp_suspend_cache_addition( false );

		add_action( 'post_updated', 'wp_save_post_revision' );
		add_action( 'do_pings', 'do_all_pings', 10 );
		add_action( 'post_updated', 'wp_check_for_changed_slugs', 12, 4 );

		// Restore DB error visibility to its pre-import state.
		if ( $this->wpdb_show_errors_backup ) {
			$wpdb->show_errors();
		} else {
			$wpdb->hide_errors();
		}
	}

	/**
	 * Back up and remove all callbacks on post/meta/term action hooks.
	 *
	 * wp_insert_post() fires ~8 do_action hooks per call, and each
	 * update_post_meta() adds 2–4 more.  For a row with 4 meta columns
	 * that totals ~20 do_action dispatches per row — all invoking callbacks
	 * registered by third-party plugins that add zero value during a CLI
	 * bulk import.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	protected function suspend_hooks(): void {
		global $wp_filter;

		$post_type = WP_Product_Post_Type::POST_TYPE;

		$hooks_to_suspend = array(
			// wp_insert_post() actions.
			'transition_post_status',
			'edit_post',
			'post_updated',
			'save_post',
			'save_post_' . $post_type,
			'wp_insert_post',

			// Status transition actions.
			'publish_' . $post_type,
			'draft_' . $post_type,
			'pending_' . $post_type,
			'private_' . $post_type,
			'future_' . $post_type,

			// Post-meta actions (fired by update_post_meta).
			'add_post_meta',
			'added_post_meta',
			'update_post_meta',
			'updated_post_meta',

			// Term assignment actions (fired by wp_set_object_terms).
			'set_object_terms',
			'added_term_relationship',
			'deleted_term_relationships',
		);

		foreach ( $hooks_to_suspend as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				$this->backed_up_filters[ $hook ] = $wp_filter[ $hook ];
				$wp_filter[ $hook ]               = new WP_Hook();
			}
		}
	}

	/**
	 * Restore all previously suspended hook callbacks.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	protected function restore_hooks(): void {
		global $wp_filter;

		foreach ( $this->backed_up_filters as $hook => $filter_object ) {
			$wp_filter[ $hook ] = $filter_object;
		}

		$this->backed_up_filters = array();
	}

	/* -------------------------------------------------------------------
	 * Memory Monitoring
	 * ----------------------------------------------------------------- */

	/**
	 * Capture a memory and GC snapshot for the current batch.
	 *
	 * Called after cleanup_between_batches() so the snapshot reflects
	 * memory after gc_collect_cycles() and cache flushing.
	 *
	 * @since 1.2.0
	 *
	 * @param int $memory_limit PHP memory_limit in bytes.
	 * @return void
	 */
	protected function log_memory_snapshot( int $memory_limit ): void {
		$current_bytes = memory_get_usage( true );
		$peak_bytes    = memory_get_peak_usage( true );
		$limit_mb      = ( $memory_limit > 0 && PHP_INT_MAX !== $memory_limit )
			? round( $memory_limit / MB_IN_BYTES, 1 )
			: -1;
		$usage_pct     = ( $memory_limit > 0 && PHP_INT_MAX !== $memory_limit )
			? round( ( $current_bytes / $memory_limit ) * 100, 1 )
			: 0.0;

		$gc = gc_status();

		$this->memory_snapshots[] = array(
			'batch'        => $this->batch_count,
			'current_mb'   => round( $current_bytes / MB_IN_BYTES, 2 ),
			'peak_mb'      => round( $peak_bytes / MB_IN_BYTES, 2 ),
			'limit_mb'     => $limit_mb,
			'usage_pct'    => $usage_pct,
			'gc_runs'      => $gc['runs'] ?? 0,
			'gc_collected' => $gc['collected'] ?? 0,
			'gc_roots'     => $gc['roots'] ?? 0,
		);
	}

	/* -------------------------------------------------------------------
	 * Utility
	 * ----------------------------------------------------------------- */

	/**
	 * Record a non-fatal per-row error.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $row_number 1-based index.
	 * @param string $message    Error description.
	 * @return void
	 */
	protected function log_error( int $row_number, string $message ): void {
		$this->errors[ $row_number ] = $message;
		++$this->counts['failed'];
	}

	/**
	 * Parse PHP memory_limit into bytes.
	 *
	 * @since 1.2.0
	 *
	 * @return int PHP_INT_MAX when limit is -1 (unlimited).
	 */
	protected function get_memory_limit_bytes(): int {
		$limit = ini_get( 'memory_limit' );
		if ( '-1' === $limit ) {
			return PHP_INT_MAX;
		}

		$unit  = strtoupper( substr( (string) $limit, -1 ) );
		$value = (int) $limit;

		switch ( $unit ) {
			case 'G':
				return $value * GB_IN_BYTES;
			case 'M':
				return $value * MB_IN_BYTES;
			case 'K':
				return $value * KB_IN_BYTES;
			default:
				return $value;
		}
	}

	/**
	 * Return the import result summary.
	 *
	 * @since 1.2.0
	 *
	 * @return array{inserted:int, updated:int, skipped:int, failed:int, errors:array<int,string>}
	 */
	protected function get_summary(): array {
		return array_merge( $this->counts, array( 'errors' => $this->errors ) );
	}
}
