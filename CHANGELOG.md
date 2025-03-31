# 📦 Changelog

All notable changes to this project will be documented in this file.

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

