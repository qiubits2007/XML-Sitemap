<?php
/**
 * XML Sitemap Generator Script
 *
 * Description:
 * This PHP script crawls one or multiple domains and generates a standards-compliant XML sitemap.
 * It supports multi thread, meta tag handling, robots.txt rules, pretty or compressed XML output,
 * resume on interruption, health check logs, email reporting, and automatic search engine pinging.
 *
 * Supports:
 * - Multiple domains (via --url=https://yourdomain1.com,https://yourdomain1.com)
 * - Dynamic priority and changefreq rules
 * - gzip output
 * - resumable crawl (via cache)
 * - Robots.txt parsing
 * - HTML meta noindex/nofollow handling
 * - Debug logging
 * - Hash key as small security feature
 * - Multithread (10)
 *
 * Usage (CLI):
 * php sitemap.php --url=https://yourdomain.com --key=YOUR_SECRET_KEY [options]
 *
 * Usage (Web):
 * sitemap.php?url=https://yourdomain.com&key=YOUR_SECRET_KEY&gzip&prettyxml
 *
 * Options:
 *  --url=               Comma-separated list of domains to crawl
 *  --key=               Required hash key to authorize script execution
 *  --depth=             Max crawl depth (default: 3)
 *  --resume             Resume crawl from previous session (uses cache)
 *  --gzip               Save sitemap as gzip-compressed .xml.gz
 *  --prettyxml          Format XML output to be human-readable
 *  --email=             Email address to receive crawl log
 *  --ping               Ping search engines after sitemap creation
 *  --ignoremeta         Ignore <meta name="robots"> rules
 *  --respectrobots      Parse and obey robots.txt disallow rules
 *  --agent=             Custom user agent string
 *  --debug              Enable detailed log output
 *  --priorityrules      Enable dynamic priority per URL
 *  --changefreqrules    Enable dynamic changefreq per URL
 *
 * Output:
 * - sitemap.xml (or .gz)
 * - logs/health_report.txt
 * - logs/crawl_log.txt
 * - cache/visited.json
 *
 * Author: Gilles Dumont (QIUBITS SARL)
 * Version: 1.0
 * License: MIT
 * Created: 2025-03-27
 */

declare(strict_types=1);

class SitemapGenerator
{
    private array $startUrls = [];
    private string $startUrl;
    private int $maxDepth;
    private bool $useGzip;
    private ?string $emailLog;
    private bool $resume;
    private bool $ignoreMeta;
    private bool $pretty;
    private bool $respectRobots;
    private bool $pingSearchEngines;
    private bool $debug;
    private string $userAgent;
    private array $visited = [];
    private array $log = [];
    private array $queue = [];
    private string $host;
    private string $scheme;
    private array $disallowedPaths = [];
    private int $crawlDelay = 0;
    private string $cacheDir = __DIR__ . "/cache";
    private string $logDir = __DIR__ . "/logs";
    private string $outputPath;
    private int $threadCount = 10;
    private bool $allowFiles = false;
    private array $ignoredExtensions = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp3', 'mp4', 'avi'];
    private array $lowPriorityPattern = ['string1', 'string2'];
    private array $highPriorityPattern = ['string3', 'string4'];
    private array $monthlyPattern = ['string1', 'string2'];
    private array $dailyPattern = ['string3', 'string4'];

    public function __construct(array $options)
    {
        $this->startUrl = rtrim($options['url'], '/');
        $this->maxDepth = (int)($options['depth'] ?? 3);
        $this->useGzip = !empty($options['gzip']);
        $this->emailLog = $options['email'] ?? null;
        $this->resume = !empty($options['resume']);
        $this->ignoreMeta = !empty($options['ignoremeta']);
        $this->pretty = !empty($options['prettyxml']);
        $this->respectRobots = !empty($options['respectrobots']);
        $this->pingSearchEngines = !empty($options['ping']);
        $this->debug = !empty($options['debug']);
        $this->outputPath = __DIR__ . '/sitemap.xml';
        $urls = is_array($options['url']) ? $options['url'] : explode(',', $options['url']);
        $this->startUrls = array_map(fn($u) => rtrim(trim($u), '/'), $urls);
        $this->userAgent = $options['agent'] ?? 'SitemapGenerator';
        $this->outputPath = $options['output'] ?? (__DIR__ . "/sitemap.xml");
        $this->threadCount = isset($options['threads']) ? max(1, (int)$options['threads']) : 10;
        $this->allowFiles = !empty($options['allowfiles']);
        $this->queue[] = [$this->startUrl, 0];
        $this->usePriorityRules = !empty($options['priorityrules']);
        $this->useChangefreqRules = !empty($options['changefreqrules']);

        $parsedUrl = parse_url($this->startUrl);
        $this->host = $parsedUrl['host'];
        $this->scheme = $parsedUrl['scheme'];

        if (!file_exists($this->cacheDir)) mkdir($this->cacheDir, 0777, true);
        if (!file_exists($this->logDir)) mkdir($this->logDir, 0777, true);
    }

    public function run(): void
    {
        foreach ($this->startUrls as $startUrl) {
            $this->startUrl = $startUrl;
            $this->queue = [ [$startUrl, 0] ];
            $this->log[] = "--- Crawling: $startUrl ---";

            $parsedUrl = parse_url($startUrl);
            $this->host = $parsedUrl['host'];
            $this->scheme = $parsedUrl['scheme'];

            if (!file_exists(__DIR__ . '/sitemaps')) {
                mkdir(__DIR__ . '/sitemaps', 0777, true);
            }

            $hostName = preg_replace('/[^a-z0-9\-\.]/i', '_', $this->host);

            $this->runCrawl();
        }
    }

    private function runCrawl(): void
    {
        $visitedCache = "{$this->cacheDir}/visited.json";
        if ($this->resume && file_exists($visitedCache)) {
            $this->visited = json_decode(file_get_contents($visitedCache), true) ?? [];
            $this->log[] = "Resuming crawl from cache.";
        }

        if ($this->respectRobots) {
            $this->loadRobotsTxt();
        }

        $mh = curl_multi_init();

        while (!empty($this->queue)) {
            $curlHandles = [];
            $batch = array_splice($this->queue, 0, $this->threadCount);
            foreach ($batch as $item) {
                [$url, $depth] = $item;
                $canonicalUrl = $this->canonicalizeUrl($url);
                if (isset($this->visited[$canonicalUrl]) || $depth > $this->maxDepth) continue;

                if ($this->isBlockedByRobots($url)) {
                    $this->log[] = "[robots.txt BLOCKED] $url";
                    $this->visited[$this->canonicalizeUrl($url)] = true;
                    continue;
                }

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_USERAGENT => $this->userAgent,
                ]);
                curl_multi_add_handle($mh, $ch);
                $curlHandles[(int)$ch] = [$ch, $url, $depth];
            }

            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            foreach ($curlHandles as $item) {
                [$ch, $url, $depth] = $item;
                $html = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if (!$html) continue;
                $canonicalUrl = $this->canonicalizeUrl($url);
                $this->visited[$canonicalUrl] = true;
                $this->log[] = "[✓] $canonicalUrl";

                if (!$html) {
                    $this->log[] = "[EMPTY RESPONSE] $url";
                    continue;
                }

                // Detect <base href>
                $baseHref = null;
                if (preg_match('/<base\s+href=["\']([^"\']+)["\']/i', $html, $baseMatch)) {
                    $baseHref = trim($baseMatch[1]);
                    if ($this->debug) $this->log[] = "[BASE HREF] Found base: $baseHref";
                }

                // Parse <meta name="robots">
                $blockedByMeta = false;
                if (!$this->ignoreMeta && preg_match_all('/<meta[^>]+name=["\']robots["\'][^>]*>/i', $html, $metaTags)) {
                    foreach ($metaTags[0] as $meta) {
                        if (preg_match('/content=["\']([^"\']+)["\']/', $meta, $match)) {
                            $metaContent = strtolower(trim($match[1]));
                            if ($this->debug) {
                                $this->log[] = "[META FOUND] robots => '$metaContent'";
                            }

                            $directives = array_map('trim', explode(',', $metaContent));
                            if (in_array('noindex', $directives) || in_array('nofollow', $directives)) {
                                $blockedByMeta = true;
                                break;
                            }
                        }
                    }
                }
                if ($blockedByMeta) {
                    $this->log[] = "[META BLOCKED] $url";
                    $this->visited[$this->canonicalizeUrl($url)] = true;
                    continue;
                }

                file_put_contents("{$this->cacheDir}/visited.json", json_encode($this->visited));

                // Extract and queue new links
                preg_match_all('/<a\s+(?:[^>]*?\s+)?href=["\']([^"\']+)["\']/i', $html, $matches);
                foreach ($matches[1] as $link) {
                    $link = $this->normalizeUrl($link, $baseHref ?? $url);

                    $canonicalLink = $this->canonicalizeUrl($link);

                    $ext = strtolower(pathinfo(parse_url($canonicalLink, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                    if (in_array($ext, $this->ignoredExtensions)) {
                        if ($this->debug) $this->log[] = "[SKIPPED EXT] $canonicalLink";
                        continue;
                    }

                    if (!$canonicalLink || isset($this->visited[$canonicalLink])) continue;

                    // Skip unwanted file types unless explicitly allowed
                    $ext = strtolower(pathinfo(parse_url($canonicalLink, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                    if (!$this->allowFiles && in_array($ext, $this->ignoredExtensions)) {
                        if ($this->debug) $this->log[] = "[SKIPPED EXT] $canonicalLink";
                        continue;
                    }

                    $linkHost = parse_url($link, PHP_URL_HOST);
                    if ($linkHost && ($linkHost === $this->host || preg_replace('/^www\\./', '', $linkHost) === preg_replace('/^www\\./', '', $this->host))) {
                        $this->queue[] = [$canonicalLink, $depth + 1];
                        if ($this->debug) $this->log[] = "[QUEUE] $canonicalLink";
                    }
                }

                if ($this->crawlDelay > 0) sleep($this->crawlDelay);
            }
        }

        curl_multi_close($mh);
        $this->createSitemap();
        $this->log[] = "Sitemap successfully created with " . count($this->visited) . " URLs on " . date('Y-m-d H:i:s') . ".";

        $statusSummary = [
            'blocked_robots' => 0,
            'blocked_meta' => 0,
            'http_errors' => 0,
            'redirects' => 0,
            'slow_pages' => 0,
        ];
        $healthReport = [];
        foreach ($this->log as $entry) {
            if (strpos($entry, '[robots.txt BLOCKED]') !== false) $statusSummary['blocked_robots']++;
            if (strpos($entry, '[META BLOCKED]') !== false) $statusSummary['blocked_meta']++;
            if (preg_match('/\[HTTP ([45][0-9]{2})\]/', $entry)) $statusSummary['http_errors']++;
            if (strpos($entry, '[REDIRECT]') !== false) $statusSummary['redirects']++;
            if (preg_match('/\(\d+\.\d+s\)/', $entry, $match) && (float)$match[0] > 3) $statusSummary['slow_pages']++;
        }
        $healthReport[] = '[HEALTH CHECK]';
        foreach ($statusSummary as $key => $val) {
            $healthReport[] = strtoupper($key) . ': ' . $val;
        }
        file_put_contents("{$this->logDir}/health_report.txt", implode("\n", $healthReport));
        file_put_contents("{$this->logDir}/crawl_log.txt", implode("\n", $this->log));

        if (!empty($this->emailLog) && filter_var($this->emailLog, FILTER_VALIDATE_EMAIL)) {
            mail($this->emailLog, "Sitemap Crawl Report", implode("\n", $this->log));
        }

        if ($this->pingSearchEngines) {
            $this->pingSearchEngines();
        }
    }

    private function loadRobotsTxt(): void
    {
        $robots = @file_get_contents("{$this->scheme}://{$this->host}/robots.txt");
        if (!$robots) {
            $this->log[] = "robots.txt not found or unreadable.";
            return;
        }

        if (preg_match('/crawl-delay:\s*(\d+)/i', $robots, $match)) {
            $this->crawlDelay = (int)$match[1];
            $this->log[] = "Crawl-delay detected: {$this->crawlDelay}s";
        }

        $lines = explode("\n", strtolower($robots));
        $currentAgent = null;
        $agents = [];

        foreach ($lines as $rawLine) {
            $line = is_string($rawLine) ? trim(preg_replace('/\s*#.*$/', '', $rawLine)) : '';
            if (!$line) continue;
            if (str_starts_with($line, 'user-agent:')) {
                $currentAgent = trim(substr($line, 11));
                $agents[$currentAgent] = [];
            } elseif ($currentAgent && (str_starts_with($line, 'disallow:') || str_starts_with($line, 'allow:'))) {
                [$directive, $path] = explode(':', $line, 2);
                $agents[$currentAgent][] = [trim($directive), trim($path)];
            }
        }

        $this->disallowedPaths = $agents[strtolower($this->userAgent)] ?? $agents['*'] ?? [];
    }

    private function isBlockedByRobots(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $matched = null;
        $matchedLen = -1;

        foreach ($this->disallowedPaths as [$directive, $rulePath]) {
            if ($rulePath === '' || str_starts_with($path, $rulePath)) {
                if (strlen($rulePath) > $matchedLen) {
                    $matched = [$directive, $rulePath];
                    $matchedLen = strlen($rulePath);
                }
            }
        }

        return $matched && $matched[0] === 'disallow';
    }

    private function normalizeUrl(string $href, string $base): ?string
    {
        $href = strtok((string)$href, '#');
        $href = is_string($href) ? $href : '';
        $href = strtok($href, '#');
        $href = is_string($href) ? trim($href) : '';

        // Ignore mailto:, javascript:, tel:, etc.
        if ($href === '' || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'tel:')) {
            return null;
        }

        // Already absolute URL
        if (parse_url($href, PHP_URL_SCHEME)) {
            return rtrim($href, '/');
        }

        // Parse base components
        $baseParts = parse_url($base);
        if (!$baseParts || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        $baseScheme = $baseParts['scheme'];
        $baseHost = $baseParts['host'];
        $basePath = $baseParts['path'] ?? '/';

        // If href starts with / => root-relative
        if (str_starts_with($href, '/')) {
            return "$baseScheme://$baseHost" . rtrim($href, '/');
        }

        // Directory path of base
        $dir = rtrim(dirname($basePath), '/');
        $fullPath = "$dir/$href";

        // Normalize double slashes
        $fullPath = preg_replace('#/+#', '/', $fullPath);

        return "$baseScheme://$baseHost$fullPath";
    }

    private function createSitemap(): void
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $urlset = $dom->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $dom->appendChild($urlset);

        foreach (array_keys($this->visited) as $url) {
            $urlEl = $dom->createElement('url');
            $urlEl->appendChild($dom->createElement('loc', htmlspecialchars($url)));
            $urlEl->appendChild($dom->createElement('lastmod', date('Y-m-d')));
            $urlEl->appendChild($dom->createElement('changefreq', $this->useChangefreqRules ? $this->getChangefreqForUrl($url) : 'weekly'));;
            $urlEl->appendChild($dom->createElement('priority', $this->usePriorityRules ? $this->getPriorityForUrl($url) : '0.5'));
            $urlset->appendChild($urlEl);
        }

        if ($this->debug) {
            $this->log[] = "[SITEMAP] Using dynamic priority: " . ($this->usePriorityRules ? 'yes' : 'no');
            $this->log[] = "[SITEMAP] Using dynamic changefreq: " . ($this->useChangefreqRules ? 'yes' : 'no');
        }

        $xmlOutput = $dom->saveXML();
        if ($this->useGzip) {
            file_put_contents($this->outputPath . '.gz', gzencode($xmlOutput));
        } else {
            file_put_contents($this->outputPath, $xmlOutput);
        }
    }

    private function pingSearchEngines(): void
    {
        $sitemapUrl = $this->startUrl . '/sitemap.xml' . ($this->useGzip ? '.gz' : '');
        $engines = [
            'Google' => 'https://www.google.com/ping?sitemap=' . urlencode($sitemapUrl),
            'Bing' => 'https://www.bing.com/ping?sitemap=' . urlencode($sitemapUrl),
            'Yandex' => 'https://webmaster.yandex.com/ping?sitemap=' . urlencode($sitemapUrl)
        ];

        foreach ($engines as $name => $url) {
            $context = stream_context_create(['http' => ['timeout' => 10]]);
            $resp = @file_get_contents($url, false, $context);
            if ($resp !== false) {
                $this->log[] = "[Ping][$name] Success – Response Length: " . strlen($resp);
            } else {
                $error = error_get_last();
                $this->log[] = "[Ping][$name] Failed – " . ($error['message'] ?? 'Unknown error');
            }
        }
    }

    private function canonicalizeUrl(?string $url): string
    {
        if (!is_string($url) || trim($url) === '') {
            return '';
        }

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return trim($url);
        }

        $scheme = $parsed['scheme'];
        $host = $parsed['host'];
        $path = $parsed['path'] ?? '/';

        $path = preg_replace('#/index\\.(html?|php)$#i', '', $path);
        $path = rtrim($path, '/');

        return "$scheme://$host" . ($path !== '' ? $path : '');
    }

    private function getPriorityForUrl(string $url): string
    {
        foreach ($this->lowPriorityPattern as $pattern) {
            if (str_contains($url, $pattern)) return '0.3';
        }
        foreach ($this->highPriorityPattern as $pattern) {
            if (str_contains($url, $pattern)) return '0.8';
        }
        if (str_ends_with($url, '/')) return '1.0';
        return '0.5';
    }

    private function getChangefreqForUrl(string $url): string
    {
        foreach ($this->monthlyPattern as $pattern) {
            if (str_contains($url, $pattern)) return 'monthly';
        }
        foreach ($this->dailyPattern as $pattern) {
            if (str_contains($url, $pattern)) return 'daily';
        }
        return 'weekly';
    }
}

$options = php_sapi_name() === 'cli'
    ? getopt("", ["url:", "depth::", "gzip", "email::", "resume", "key:", "ignoremeta", "prettyxml", "respectrobots", "ping", "agent::", "debug", "output::", "threads::", "allowfiles::", "priorityrules", "changefreqrules"])
    : [
        'url' => $_GET['url'] ?? null,
        'depth' => $_GET['depth'] ?? null,
        'gzip' => isset($_GET['gzip']),
        'email' => $_GET['email'] ?? null,
        'resume' => isset($_GET['resume']),
        'key' => $_GET['key'] ?? null,
        'ignoremeta' => isset($_GET['ignoremeta']),
        'prettyxml' => isset($_GET['prettyxml']),
        'respectrobots' => isset($_GET['respectrobots']),
        'ping' => isset($_GET['ping']),
        'debug' => isset($_GET['debug']),
        'agent' => $_GET['agent'] ?? null,
        'output' => $_GET['output'] ?? null,
        'threads' => $_GET['threads'] ?? null,
        'allowfiles' => isset($_GET['allowfiles']),
        'priorityrules' => isset($_GET['priorityrules']),
        'changefreqrules' => isset($_GET['changefreqrules'])
    ];

$authorized_hash = 'YOUR_SECRET_KEY';

if (!isset($options['key']) || $options['key'] !== $authorized_hash) {
    die("Unauthorized. Valid key parameter required.\n");
}

if (empty($options['url'])) {
    die("Missing required --url parameter.\n");
}

$generator = new SitemapGenerator($options);
$generator->run();