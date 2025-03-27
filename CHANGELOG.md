# 📦 Changelog

All notable changes to this project will be documented in this file.

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

