# 🗺️ XML Sitemap Generator (PHP)

A powerful and customizable sitemap generator written in PHP (PHP 8+).  
It crawls one or multiple domains, respects `robots.txt`, follows meta directives, supports resumable sessions, sends logs by email, and can even notify search engines when the sitemap is ready.

---

## ✅ Features

- 🔗 Multi-domain support (comma-separated URLs)
- 📑 Combined sitemap for all domains
- 🧭 Crawling depth control
- 🔍 `robots.txt` and `<meta name="robots">` handling
- 🔁 Resumable crawl via cache (optional)
- 💣 `--resetcache` to force full crawl (new!)
- 💣 `--resetlog` to delete old log files (new!)
- 🧠 Dynamic priority & changefreq rules (via config or patterns)
- 🧹 Pretty or single-line XML output
- 📦 GZIP compression (optional)
- 📧 Log by email
- 🛠 Health check report
- 📡 Ping Google/Bing/Yandex
- 🧪 Debug mode with detailed logs

---

## 🚀 Requirements

- PHP 8.0 or newer
- `curl` and `dom` extensions enabled
- Write permissions to the script folder (for logs/cache/sitemaps)

---

## ⚙️ Usage (CLI)

```bash
php sitemap.php \
  --url=https://yourdomain.com,https://blog.yourdomain.com \
  --key=YOUR_SECRET_KEY \
  [options]
```

## 🌐 Usage (Browser)

```url
sitemap.php?url=https://yourdomain.com&key=YOUR_SECRET_KEY&gzip&prettyxml
```

---

## 🧩 Options

| Option              | Description |
|---------------------|-------------|
| `--url=`            | Comma-separated domain list to crawl (required) |
| `--key=`            | Secret key to authorize script execution (required) |
| `--output=`         | Output path for the sitemap file |
| `--depth=`          | Max crawl depth (default: 3) |
| `--gzip`            | Export sitemap as `.gz` |
| `--prettyxml`       | Human-readable XML output |
| `--resume`          | Resume from last crawl using `cache/visited.json` |
| `--resetcache`      | Force fresh crawl by deleting the cache (NEW) |
| `--resetlog`        | Clear previous crawl logs before start (NEW) |
| `--filters`         | Enable external filtering from `filter_config.json` |
| `--priorityrules`   | Enable dynamic `<priority>` based on URL patterns |
| `--changefreqrules` | Enable dynamic `<changefreq>` based on URL patterns |
| `--ignoremeta`      | Ignore `<meta name="robots">` directives |
| `--respectrobots`   | Obey rules in `robots.txt` |
| `--email=`          | Send crawl log to this email |
| `--ping`            | Notify search engines after sitemap generation |
| `--threads=`        | Number of concurrent crawl threads (default: 10) |
| `--agent=`          | Set a custom User-Agent |
| `--debug`           | Output detailed log info for debugging |

---

## 📁 Output Files

- `sitemap.xml` (or `.gz` if `--gzip` is used)
- `cache/visited.json` → stores crawl progress (used with `--resume`)
- `logs/crawl_log.txt` → full crawl log
- `logs/health_report.txt` → summary of crawl (errors, speed, blocks)

---

## ⚙️ External Filter Config

Create a `config/filter.json` to define your own include/exclude patterns and dynamic rules:

```json
{
  "excludeExtensions": ["jpg", "png", "zip", "docx"],
  "excludePatterns": ["*/private/*", "debug"],
  "includeOnlyPatterns": ["blog", "news"],
  "priorityPatterns": {
    "high": ["blog", "news"],
    "low": ["impressum", "privacy"]
  },
  "changefreqPatterns": {
    "daily": ["blog", "news{
      "excludeExtensions": ["jpg", "png", "docx", "zip"],
      "excludePatterns": [],
      "includeOnlyPatterns": [],
      "priorityPatterns": {
        "high": [
          "news",
          "blog",
          "offers"
        ],
        "low": [
          "terms-and-conditions",
          "legal-notice",
          "privacy-policy"
        ]
      },
      "changefreqPatterns": {
        "daily": [
          "news",
          "blog",
          "offers"
        ],
        "monthly": [
          "terms-and-conditions",
          "legal-notice",
          "privacy-policy"
        ]
      }
      }"],
    "monthly": ["impressum", "agb"]
  }
}
```

Activate with:
```bash
--filters --priorityrules --changefreqrules
```

---

## 📬 Ping Support

With `--ping` enabled, the script will notify:

- Google: `https://www.google.com/ping`
- Bing: `https://www.bing.com/ping`
- Yandex: `https://webmaster.yandex.com/ping`

---

## 🔐 Security

The script **requires a secret key** (`--key=` or `key=`) to run.  
Set it inside the script:

```php
$authorized_hash = 'YOUR_SECRET_KEY';
```

---

## 📤 Email Log

Send crawl reports to your inbox with:

```bash
--email=you@yourdomain.com
```

Your server must support the `mail()` function.

---

## 🧪 Debugging

Enable `--debug` to log everything:
- Pattern matches
- Skipped URLs
- Meta robots blocking
- Robots.txt interpretation
- Response times
- Log file resets

---

## 📄 License

MIT License  
Feel free to fork, modify, or contribute!

---

## 👤 Author

Built by Gilles Dumont (Qiubits SARL)  
Contributions and feedback welcome.