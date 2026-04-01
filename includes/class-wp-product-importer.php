<?php
/**
 * CSV importer for WP Product Importer.
 *
 * Concrete implementation of WP_Product_Importer_Base that reads from a
 * CSV file using PHP Generators for O(1) memory usage.
 *
 * Supported delimiters: comma, tab, pipe, semicolon (auto-detected).
 *
 * @package WP_Product_Importer
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_Product_Importer
 *
 * @since 1.0.0
 */
class WP_Product_Importer extends WP_Product_Importer_Base {

	/**
	 * Absolute path to the CSV import file.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $file_path;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path Absolute path to the CSV file.
	 * @param array  $options {
	 *     Optional. Import options (see WP_Product_Importer_Base::__construct()).
	 * }
	 */
	public function __construct( string $file_path, array $options = array() ) {
		parent::__construct( $options );
		$this->file_path = $file_path;
	}

	/* -------------------------------------------------------------------
	 * Public API
	 * ----------------------------------------------------------------- */

	/**
	 * Return the total file size in bytes for byte-based progress tracking.
	 *
	 * Uses filesize() — an O(1) stat call. Combined with the byte positions
	 * yielded by stream_rows(), this enables accurate progress tracking
	 * without pre-reading the file.
	 *
	 * @since 1.0.0
	 *
	 * @return int File size in bytes.
	 * @throws RuntimeException On invalid file.
	 */
	public function get_file_size(): int {
		$this->validate_source();
		return filesize( $this->file_path );
	}

	/**
	 * CSV sources use a byte-based progress bar (via get_file_size()).
	 *
	 * Returning null signals the CLI to call get_file_size() instead of
	 * using a row-count bar.
	 *
	 * @since 1.2.0
	 *
	 * @return null
	 */
	public function get_total_rows(): ?int {
		return null;
	}

	/* -------------------------------------------------------------------
	 * Source Validation (WP_Product_Importer_Base contract)
	 * ----------------------------------------------------------------- */

	/**
	 * Assert the file exists, is readable, and is non-empty.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 * @throws RuntimeException On failure.
	 */
	protected function validate_source(): void {
		if ( ! file_exists( $this->file_path ) ) {
			throw new RuntimeException( sprintf( 'File not found: %s', $this->file_path ) );
		}
		if ( ! is_readable( $this->file_path ) ) {
			throw new RuntimeException( sprintf( 'File is not readable: %s', $this->file_path ) );
		}
		if ( 0 === filesize( $this->file_path ) ) {
			throw new RuntimeException( sprintf( 'File is empty: %s', $this->file_path ) );
		}
	}

	/* -------------------------------------------------------------------
	 * Streaming CSV Generator (WP_Product_Importer_Base contract)
	 * ----------------------------------------------------------------- */

	/**
	 * Stream a CSV file one logical row at a time.
	 *
	 * fgetcsv() implements RFC 4180: fields containing embedded newlines
	 * must be enclosed in double-quotes, and fgetcsv() reads past those
	 * newlines returning the entire value as a single string.
	 *
	 * The Generator key is the ftell() byte position after each row,
	 * used as the progress token (bytes read so far).
	 *
	 * Memory: O(1) — one logical row and the headers array at a time.
	 *
	 * @since 1.0.0
	 *
	 * @return Generator<int, array<string, string>>
	 * @throws RuntimeException On open failure or missing header.
	 */
	protected function stream_rows(): Generator {
		$handle = fopen( $this->file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		if ( false === $handle ) {
			throw new RuntimeException( sprintf( 'Cannot open file: %s', $this->file_path ) );
		}

		// Handle UTF-8 BOM (Excel sometimes prepends three invisible bytes).
		$bom = fread( $handle, 3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fread
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $handle );
		}

		// Record the byte offset of real data (after BOM, or 0).
		$data_start = ( "\xEF\xBB\xBF" === $bom ) ? 3 : 0;

		// Read the header line for delimiter sniffing, then seek back so
		// fgetcsv() reads the header row from the correct position.
		$sample    = (string) fgets( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fgets
		fseek( $handle, $data_start );
		$delimiter = $this->detect_csv_delimiter( $sample );
		$headers   = fgetcsv( $handle, 0, $delimiter ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fgetcsv

		if ( false === $headers || empty( $headers ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			throw new RuntimeException( 'CSV file has no header row.' );
		}

		$headers   = array_map( 'trim', array_map( 'strtolower', $headers ) );
		$col_count = count( $headers );

		while ( false !== ( $raw = fgetcsv( $handle, 0, $delimiter ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( 1 === count( $raw ) && '' === $raw[0] ) {
				continue;
			}

			yield ftell( $handle ) => array_combine( $headers, array_pad( $raw, $col_count, '' ) );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
	}

	/* -------------------------------------------------------------------
	 * CSV Utilities
	 * ----------------------------------------------------------------- */

	/**
	 * Detect CSV delimiter by sampling the header line.
	 *
	 * Checks comma, tab, pipe, and semicolon. Returns the delimiter that
	 * occurs the most frequently in the sampled line.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sample First line of the file (already read by the caller).
	 * @return string Detected delimiter character.
	 */
	private function detect_csv_delimiter( string $sample ): string {
		$delimiters = array(
			','  => substr_count( $sample, ',' ),
			"\t" => substr_count( $sample, "\t" ),
			'|'  => substr_count( $sample, '|' ),
			';'  => substr_count( $sample, ';' ),
		);

		arsort( $delimiters );
		return (string) array_key_first( $delimiters );
	}
}
