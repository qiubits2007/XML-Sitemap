# 📦 Changelog

All notable changes to this project will be documented in this file.

---

## [1.5.0] – 2025-04-03

### ✨ Added
- Unified structured logging system with timestamp, log level, and message
- JSON log export (`logs/crawl_log.json`)
- Optional styled HTML log report (`logs/crawl_log.html`) using `log_template_dark.html`
- Visual crawl map (JSON + HTML) via `--graphmap` and optional `--publicbase`
- `addLog($message, $level)` helper to standardize internal logging
- `flattenLog()` to convert structured logs into text for plaintext output
- Export of structured logs for email reporting (as attached `.txt`)
- Timestamped email reports with proper MIME multipart formatting
- Optional sender via `--from` parameter
- New option `--publicbase` to define public base URL for sitemap/map references

### 🛠️ Changed
- All `$this->log[]` replaced with `addLog()` for unified structure
- `crawl_log.txt` now uses flattened structured log entries
- `pingSearchEngines()` now correctly resolves and logs ping URLs
- Sitemap generation is now deferred to `finalizeAfterRun()` for logical sequencing
- HTML template loading is moved to external file in `/templates`

### 🧹 Fixed
- Double log entries and inconsistencies in multi-domain mode
- Sitemap was previously overwritten in multi-domain mode without `--splitbysite`
- Health check parsing now handles structured logs without `str_contains()` error
- Fixed missing sitemap summary when no split is used

---

## [1.4.0] - 2025-03-28

### Added
- 🧠 Visual Crawl Map (Graph) now available:
  - JSON export of link structure (`graph.json`)
  - Interactive dark-themed `crawl_map.html` with zoom, drag and tooltips
- 🧹 Reset options:
  - `--resetcache` to clear previous crawl state
  - `--resetlog` to clear old logs before new crawl
- 📨 Improved mail logging: email status (success/failure) is now logged
- 🧩 Improved support for split-by-domain with dynamic `--output` rewriting

### Fixed
- Bug where `mail()` silently failed if sendmail config was missing
- Graph export block was not triggered in some conditions – now runs reliably after each crawl

### Changed
- Dynamic sitemap splitting now also works with `--output=...`
- Canonical URL generation and filters are more robust for edge cases

---

## [1.3.0] - 2025-03-31

### Added

- New option: `--splitbysite` to generate separate sitemaps per domain
- Automatic generation of `sitemap_index.xml` when using `--splitbysite`
- Search engine pinging now targets `sitemap_index.xml` if split mode is active

---

## [1.2.0] - 2025-03-28

### ✨ Added

- `--resetcache`: Forces fresh crawl by deleting `visited.json` before crawling
- `--resetlog`: Deletes `logs/crawl_log.txt` and `logs/health_report.txt` before crawling
- Log count of removed cache entries during reset
- Debug logging of reset actions and loaded filters
- External `filter.json` support (optional filtering of URLs and file extensions)
- Pattern-based priority and changefreq rules via `priorityPatterns` and `changefreqPatterns`
- Logging of matched priority and frequency rules
- Multi-domain support (`--url=a.com,b.com`)
- Per-domain crawl logging with combined sitemap
- Canonical URL normalization (e.g. removes `/index.html`)
- Better detection of `<meta name="robots">` (now finds tags even on same line)

### 🛠 Improved

- XML output logic cleaned and pretty-printing stabilized
- Better CLI option parsing with `array_key_exists` safety
- Fully PSR-12 compliant code style
- Trimmed, safer `normalizeUrl()` with validation
- Optional debug output logs to help diagnose issues
- Automatic sitemap folder creation only if needed
- Dynamic configuration through flags: `--filters`, `--priorityrules`, `--changefreqrules`

### 🐞 Fixed

- Robots meta tag parsing with `content="all"`
- Ignore invalid or broken URLs gracefully
- Avoid duplicated `<priority>` and `<changefreq>` entries
- Better error handling in multithreaded curl execution
- Files with excluded extensions no longer enqueued
- Logs and health reports now resettable with `--resetlog`

---

## [1.1.0] – 2025-03-27

### Added
- 🔔 **Detailed Ping Logging**: The `pingSearchEngines()` method now logs:
    - Which search engines were notified
    - Whether the ping was successful or failed
    - The length of the response (if successful)
    - The error message (if failed)
- Adds HTTP timeout context to each ping request

---

## [1.0.0] – 2025-03-27

### Added
- Initial public release of the Sitemap Generator Script
- Multi-domain support via `--url=domain1,domain2,...`
- Combined sitemap output for all domains into `sitemap.xml`
- Support for `robots.txt` (disallow and crawl-delay)
- Support for `<meta name="robots" content="noindex,nofollow">`
- Pretty-printed or single-line XML output (`--prettyxml`)
- GZIP support (`--gzip`)
- Resumable crawl using `--resume` and `visited.json`
- Health check report output (`logs/health_report.txt`)
- Full crawl log output (`logs/crawl_log.txt`)
- Optional email delivery of crawl log (`--email`)
- Ping search engines on completion (`--ping`)
- Debug output mode (`--debug`)
- Dynamic priority and changefreq rules (`--priorityrules`, `--changefreqrules`)
- Command-line and web interface usage
- Secret hash-based access protection (`--key` required)

---

