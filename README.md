# WP-CLI Optimized Import Engine

A high-performance WordPress plugin that bulk-imports products(custom post type) into a custom post type (`wp-product`) via WP-CLI. Supports two source types — **CSV files** and **existing database tables** — with identical performance guarantees for both.

---

## Features

- **Two import sources** — CSV file or any custom DB table in the current WordPress database
- **Memory-safe streaming** — PHP Generators keep peak RAM at O(batch_size), never O(total rows)
- **Cursor-based DB pagination** — `WHERE id > $last` instead of `LIMIT/OFFSET`; O(1) per page on any table size
- **Idempotent** — keyed on `_wppi_source_id` post meta; re-running never creates duplicates
- **Batched transactions** — each batch runs inside `START TRANSACTION / COMMIT` with `ROLLBACK` on failure
- **Hook suspension** — third-party `save_post`, `update_post_meta`, and term hooks are silenced during import, then restored
- **Dry-run mode** — validate every row without writing a single byte to the database
- **Porcelain output** — machine-readable counters for CI/CD pipelines
- **Memory health report** — per-batch RAM and GC snapshots via `--monitor-memory`
- **WPCS compliant** — all direct queries justified and suppressed per WordPress Coding Standards

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| WP-CLI | 2.0+ |

---

## Installation

1. Upload the `wp-product-importer` folder to `wp-content/plugins/`.
2. Activate the plugin via **Plugins → Installed Plugins** or with WP-CLI:

```bash
wp plugin activate wp-product-importer --path=/path/to/wordpress
```

---

## CSV Import

### Column naming convention

| Column | Required | Description |
|---|---|---|
| `source_id` | Yes | Unique external ID used for idempotency |
| `post_title` | Yes | Product title |
| `post_content` | No | Product body content |
| `post_excerpt` | No | Short description |
| `post_status` | No | `publish` (default), `draft`, `pending`, `private`, `future` |
| `post_date` | No | Publish date (any format parseable by `date_create()`) |
| `meta_*` | No | Any column prefixed `meta_` is stored as `_{key}` post meta |
| `tax_*` | No | Any column prefixed `tax_` assigns comma-separated taxonomy terms |

**Supported delimiters:** comma, tab, pipe, semicolon (auto-detected from the header row).

**UTF-8 BOM** is stripped automatically (Excel-exported files are handled correctly).

### Sample CSV

```csv
source_id,post_title,post_content,post_status,meta_price,meta_sku,tax_wp-product-cat
P001,Running Shoes,"Lightweight trail runners.",publish,59.99,SKU-001,Footwear
P002,Yoga Mat,"Non-slip 6mm mat.",publish,29.99,SKU-002,Fitness
P003,Water Bottle,"BPA-free 1L bottle.",draft,14.99,SKU-003,Accessories
```

### Commands

```bash
# Basic import
wp wp-product import /path/to/products.csv --path=/path/to/wordpress

# Custom batch size
wp wp-product import /path/to/products.csv --batch-size=100 --path=/path/to/wordpress

# Update existing products instead of skipping
wp wp-product import /path/to/products.csv --update --path=/path/to/wordpress

# Dry-run — validate without writing
wp wp-product import /path/to/products.csv --dry-run --path=/path/to/wordpress

# Machine-readable output for scripts
wp wp-product import /path/to/products.csv --porcelain --path=/path/to/wordpress

# Per-batch memory and GC report
wp wp-product import /path/to/products.csv --monitor-memory --path=/path/to/wordpress
```

### CSV options

| Flag | Default | Description |
|---|---|---|
| `--batch-size=<n>` | `50` | Rows per DB transaction (max 500) |
| `--dry-run` | off | Validate only; no DB writes |
| `--update` | off | Update existing products on source ID match |
| `--porcelain` | off | Print `inserted=N,updated=N,skipped=N,failed=N` and exit |
| `--monitor-memory` | off | Print per-batch RAM and GC snapshot table |

---

## Database Table Import

Import directly from any custom table in the current WordPress database. Only `post_title` and `post_content` are read from the source table — no extra columns needed.

### Source table requirements

| Column | Required | Description |
|---|---|---|
| `id` (or `--id-column`) | Yes | Integer primary key; used as `source_id` for idempotency and as the pagination cursor |
| `post_title` | Yes | Product title |
| `post_content` | No | Product body content |

> The id column should be an **integer type with an index** (primary key is ideal). A warning is shown if a non-integer type is detected, as cursor pagination uses integer comparison.

### Commands

```bash
# Basic import (id column defaults to 'id')
wp wp-product import-table legacy_products --path=/path/to/wordpress

# Map a custom column as the source ID and cursor
wp wp-product import-table legacy_products --id-column=product_id --path=/path/to/wordpress

# Dry-run to preview without writing
wp wp-product import-table legacy_products --dry-run --path=/path/to/wordpress

# Update existing products instead of skipping
wp wp-product import-table legacy_products --update --path=/path/to/wordpress

# Larger batch size with memory monitoring
wp wp-product import-table legacy_products --batch-size=100 --monitor-memory --path=/path/to/wordpress

# Machine-readable output for scripting pipelines
wp wp-product import-table legacy_products --porcelain --path=/path/to/wordpress
```

### DB table options

| Flag | Default | Description |
|---|---|---|
| `--id-column=<col>` | `id` | Column used as `source_id` and pagination cursor |
| `--batch-size=<n>` | `50` | Rows per DB transaction (max 500) |
| `--dry-run` | off | Validate only; no DB writes |
| `--update` | off | Update existing products on source ID match |
| `--porcelain` | off | Print `inserted=N,updated=N,skipped=N,failed=N` and exit |
| `--monitor-memory` | off | Print per-batch RAM and GC snapshot table |

### Safety validation

The plugin validates the source table in five steps before any row is read:

1. Table name must contain only letters, digits, and underscores
2. WordPress core tables (`wp_posts`, `wp_users`, etc.) are blocked
3. Table must exist in the current database
4. `post_title` and the id column must be present
5. Non-integer id column emits a warning (non-fatal)

---

## Performance Architecture

### CSV — streaming with PHP Generators

```
fopen() → fgetcsv() [one row] → yield → batch[] → flush_batch() → discard
                                                  ↑__________________________↓
```

Peak RAM = O(batch_size), never O(file size). Progress is tracked via `ftell()` byte position for an accurate byte-based progress bar.

### DB table — cursor pagination

```sql
-- Page 1
SELECT `id` AS `source_id`, `post_title`, `post_content`
FROM `legacy_products`
WHERE `id` > 0
ORDER BY `id` ASC
LIMIT 50;

-- Page 2 (cursor = last id from page 1)
SELECT `id` AS `source_id`, `post_title`, `post_content`
FROM `legacy_products`
WHERE `id` > 50
ORDER BY `id` ASC
LIMIT 50;
```

`LIMIT/OFFSET` degrades to O(M) because MySQL scans and discards M rows per page. Cursor pagination starts from the index at every page — O(1) cost regardless of table size.

### Per-batch optimisations applied to both sources

| Optimisation | What it prevents |
|---|---|
| `WP_IMPORTING` constant | Pingback scheduling, slow meta lookups |
| Hook suspension | ~20 `do_action` calls per row from SEO/cache/audit plugins |
| `wp_suspend_cache_addition()` | Object cache bloat from imported data |
| `wp_defer_term_counting()` | Per-post taxonomy recount (batched to end) |
| `START TRANSACTION / COMMIT` | Per-row disk sync overhead |
| `ROLLBACK` on exception | Partial batch writes |
| `$wpdb->suppress_errors()` | Deadlock/duplicate-key noise on stderr |
| `gc_collect_cycles()` | Circular reference accumulation between batches |
| `$wpdb->flush()` + cache flush | Query log and object cache growth |
| MySQL keepalive ping | "MySQL server has gone away" on long batches |
| Direct `$wpdb->insert()` for new meta | Eliminates redundant SELECT per meta field on insert |
| Batch `IN(...)` lookup for idempotency | Eliminates N+1 queries per batch |

---

## Output Examples

### Normal mode

```
Importing from table: legacy_products (id-column: id)
Importing  100% [====================================] 0:02 / 0:02

Import complete.
  Metric                     Count
  ------------------------------------
  Inserted                   842
  Updated                    0
  Skipped                    58
  Failed                     0
  Total rows processed       900
```

### Porcelain mode (`--porcelain`)

```
inserted=842,updated=0,skipped=58,failed=0
```

### Memory health report (`--monitor-memory`)

```
━━━ Memory Health Report ━━━
  Status: ✅ Stable (memory growth ≤ 20%)

  Metric                         Value
  ────────────────────────────────────────────
  PHP memory_limit               512 MB
  Memory after batch 1           28.50 MB
  Memory after last batch        29.25 MB
  Peak memory (lifetime)         31.00 MB
  Growth (first → last)          +2.6%
  GC cycles run                  18
  GC objects freed               1,204
```

---

## Custom Post Type

The plugin registers the `wp-product` CPT with two taxonomies:

| Taxonomy | Slug | Type |
|---|---|---|
| Product Category | `wp-product-cat` | Hierarchical |
| Product Tag | `wp-product-tag` | Flat |

Tax columns in CSV or matching table columns follow the pattern `tax_wp-product-cat`, `tax_wp-product-tag`.

---

## Concurrency

A WordPress transient lock (`wppi_import_lock`) prevents two imports from running simultaneously. If an import crashes and leaves the lock set:

```bash
wp transient delete wppi_import_lock --path=/path/to/wordpress
```

---

## File Structure

```
wp-product-importer/
├── wp-cli-optimized-import-engine.php   # Bootstrap, autoloader, activation hooks
└── includes/
    ├── class-wp-product-post-type.php   # CPT + taxonomy registration
    ├── class-wp-product-importer-base.php  # Abstract base — shared batch engine
    ├── class-wp-product-importer.php    # CSV source (extends base)
    ├── class-wp-product-db-importer.php # DB table source (extends base)
    └── class-wp-product-cli-command.php # WP-CLI commands
```

---

## License

GPL-2.0-or-later — see [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html).
