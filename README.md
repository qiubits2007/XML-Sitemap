# 🗺️ XML Sitemap Generator (PHP)

A powerful and customizable sitemap generator written in PHP (PHP 8+).  
It crawls one or multiple domains, respects `robots.txt`, follows meta directives, supports resumable sessions, sends logs by email, and can even notify search engines when the sitemap is ready.

---

## ✅ Features

- 🔗 Multi-domain support (comma-separated URLs)
- 📑 Combined sitemap for all domains
- 🧭 Crawling depth control
- 🔍 `robots.txt` and `<meta name="robots">` handling
- 🔁 Resumable crawl via cache
- 🧠 Dynamic priority & changefreq rules
- 🧹 Pretty or single-line XML output
- 📦 GZIP compression (optional)
- 📧 Log by email
- 🛠 Health check report
- 📡 Ping Google/Bing/Yandex

---

## 🚀 Requirements

- PHP 8.0 or newer
- curl extension enabled
- Write permissions to the script folder (for logs/cache/sitemaps)

---

## ⚙️ Usage (CLI)

```bash
php sitemap.php --url=https://yourdomain.com,https://blog.yourdomain.com --key=YOUR_SECRET_KEY [options]
```

### 🌐 Usage (Browser)

```url
sitemap.php?url=https://yourdomain.com&key=YOUR_SECRET_KEY&gzip&prettyxml
```

---

## 🧩 Options

| Option              | Description |
|---------------------|-------------|
| `--url=`            | Comma-separated domain list to crawl (required) |
| `--key=`            | Secret key to authorize script execution (required) |
| `--output=`         | By default, the sitemap is saved as `sitemap.xml` in the script directory. You can change this with this option. Example: /var/www/vhosts/yourdomain.com/httpdocs/sitemap.xml |
| `--changefreqrules` | Enable dynamic `<changefreq>` per URL |
| `--depth=`          | Max crawl depth (default: 3) |
| `--resume`          | Resume from last crawl using cache |
| `--gzip`            | Export sitemap as `.gz` |
| `--prettyxml`       | Human-readable XML output |
| `--ignoremeta`      | Ignore `<meta name="robots">` |
| `--respectrobots`   | Obey rules in `robots.txt` |
| `--email=`          | Send crawl log to this email |
| `--ping`            | Ping search engines after sitemap creation |
| `--debug`           | Output detailed log info |
| `--agent=`          | Set a custom User-Agent |
| `--priorityrules`   | Enable dynamic `<priority>` per URL |
| `--changefreqrules` | Enable dynamic `<changefreq>` per URL |

---

## 📁 Output Files

- `sitemap.xml` (or `.gz` if `--gzip` used)
- `cache/visited.json` → stores crawl progress (for resume)
- `logs/crawl_log.txt` → full crawl log
- `logs/health_report.txt` → overview of blocked pages, errors, speed

---

## 📬 Ping Support (if `--ping` is enabled)

The script will notify:

- Google: `https://www.google.com/ping`
- Bing: `https://www.bing.com/ping`
- Yandex: `https://webmaster.yandex.com/ping`

---

## 🔒 Security

To prevent unauthorized access, the script **requires a secret hash key**:
Only requests with a matching `--key` or `key=` parameter will be accepted.

---

## 🛠 Configuration (Before First Use)

Before using the script, make sure to check and optionally customize the following settings inside `sitemap.php`:

- **Authorization Hash**  
  Replace the value of `$authorized_hash` with your own secret string to prevent unauthorized access.
  ```php
  $authorized_hash = 'YOUR_SECRET_KEY';
  ```

- **Default Patterns (optional)**  
  The script uses internal lists to set priority and changefreq rules. You can adjust them directly in the class:
  ```php
  private array $lowPriorityPattern = ['contact', 'imprint', 'terms'];
  private array $highPriorityPattern = ['news', 'blog'];
  private array $monthlyPattern = ['contact'];
  private array $dailyPattern = ['news'];
  ```

- **Email Sending**  
  Make sure your server supports PHP `mail()` function if you want to use `--email` for crawl log delivery.

- **Folder Permissions**  
  Ensure write permissions for:
  - `cache/`
  - `logs/`
  - `sitemaps/` (if used)


---


## 🛠 License

MIT License  
Feel free to modify, extend or contribute!

---

## 👤 Author

Built by Gilles Dumont (Qiubits SARL)  
Feedback, issues, and contributions welcome.
