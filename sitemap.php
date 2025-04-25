<?php
/**
 * XML Sitemap Generator Script
 *
 * Description:
 * This advanced PHP crawler generates a standards-compliant XML sitemap by crawling one or multiple domains.
 * It supports multithreading, intelligent URL filtering, priority and change frequency rules, and flexible output options.
 * The script is designed for automation and high-performance crawling, ideal for large websites or SEO-critical environments.
 *
 * Key Features:
 * - Multi-domain crawling (via --url=https://domain1.com,https://domain2.com)
 * - Dynamic priority and changefreq assignment via config file
 * - Pretty-printed or GZIP-compressed XML output
 * - Resumable crawl sessions (using cache/visited.json)
 * - Full support for robots.txt and <meta name="robots"> directives
 * - Health check reports (slow pages, redirects, blocked URLs, errors)
 * - Email reporting (including crawl log as attachment with customizable sender)
 * - Search engine pinging (Google, Bing, Yandex)
 * - Visual crawl map (JSON export + interactive HTML via Graphviz layout)
 * - Smart splitting of sitemaps when exceeding 50,000 URLs
 * - Optional per-domain sitemap splitting (--splitbysite)
 * - Powerful CLI interface with over 20 options
 * - Secure execution using a required access hash (--key)
 * - Debug mode with detailed internal logging for developers
 *
 * Tech Stack:
 * - Pure PHP (no external dependencies required)
 * - Compatible with PHP 8.0+
 *
 * Usage (CLI):
 * php sitemap.php --url=https://yourdomain.com --key=YOUR_SECRET_KEY [options]
 *
 * Usage (Web):
 * sitemap.php?url=https://yourdomain.com&key=YOUR_SECRET_KEY&gzip&prettyxml
 *
 * Options:
 *  --url=               Required. Comma-separated list of domains or start URLs to crawl
 *  --key=               Required. Authorization key to run the script
 *
 * Crawl Behavior:
 *  --depth=             Max crawl depth (default: 3)
 *  --threads=           Number of parallel threads (default: 10)
 *  --agent=             Custom user agent string
 *  --resume             Resume from previous crawl session using cache
 *  --resetcache         Clear previous crawl cache before starting
 *  --resetlog           Clear previous log files before starting
 *  --ignoremeta         Ignore <meta name="robots"> directives
 *  --respectrobots      Obey robots.txt disallow rules
 *  --allowfiles         Allow non-HTML file links (e.g., PDFs, images)
 *
 * Output:
 *  --output=            Custom output path for the sitemap file
 *  --gzip               Save sitemap as compressed .xml.gz
 *  --prettyxml          Pretty-print the XML output
 *  --splitbysite        Create separate sitemaps per domain
 *
 * Filtering & Rules:
 *  --filters            Enable filtering based on filter_config.json
 *  --priorityrules      Use priority rules from config
 *  --changefreqrules    Use changefreq rules from config
 *
 * Crawl Visualization:
 *  --graphmap           Generate crawl map as JSON and interactive HTML
 *  --publicbase=        Public base URL for graph map links (e.g., https://example.com/sitemaps)
 *
 * Logging & Mail:
 *  --email=             Email address to send the crawl report to
 *  --from=              Custom sender address for email reports
 *  --debug              Enable verbose logging (logs internal decisions)
 *
 * SEO:
 *  --ping               Notify search engines after sitemap generation
 *
 * Output Files (Default):
 *  - sitemap.xml        The generated sitemap (or sitemap-*.xml if split by site)
 *  - sitemap.xml.gz     (Optional) Compressed version if --gzip is enabled
 *  - sitemaps/          Folder containing domain-based sitemaps (if --splitbysite is enabled)
 *  - sitemap_index.xml  Master index for multi-domain sitemaps
 *  - cache/visited.json Stores visited URLs for resume support
 *  - logs/crawl_log.txt Full crawl log
 *  - logs/health_report.txt Crawl statistics and health summary
 *  - logs/crawl_report_YYYYMMDD_HHMMSS.txt Email attachment version of the log
 *  - crawl_map.json     (Optional) Graph data of the crawl structure
 *  - crawl_map.html     (Optional) Interactive crawl visualization
 *
 * Author: Gilles Dumont (QIUBITS SARL)
 * Version: 1.6.0
 * License: MIT
 * Created: 2025-04-25
 */

declare(strict_types=1);

class SitemapGenerator
{

    // Main class for generating XML sitemaps from one or multiple domains.
    // Handles crawling, sitemap formatting, meta tag/robots.txt rules, logging and more.

    // === Basic configuration ===
    private array $startUrls = [];
    private string $startUrl = '';
    private int $maxDepth = 3;
    private string $outputPath = '';
    private string $userAgent = 'SitemapGenerator';
    private int $threadCount = 10;
    private bool $allowFiles = false;
    private bool $exportGraph = false;
    private int $sitemapLimit = 50000; // Max URLs per sitemap file

    // === Feature toggles / flags ===
    private bool $useGzip = false;
    private bool $resume = false;
    private bool $ignoreMeta = false;
    private bool $pretty = false;
    private bool $respectRobots = false;
    private bool $pingSearchEngines = false;
    private bool $debug = false;
    private bool $useFilters = false;
    private bool $usePriorityRules = false;
    private bool $useChangefreqRules = false;
    private bool $resetCache = false;
    private bool $resetLog = false;
    private bool $splitBySite = false;

    // === Optional configuration ===
    private ?string $emailLog = null;
    private ?string $fromEmail = null;
    private ?string $publicBase = null;

    // === Internal working data ===
    private array $visited = [];
    private array $globalLog = [];
    private array $log = [];
    private array $queue = [];
    private string $host = '';
    private string $scheme = '';
    private array $disallowedPaths = [];
    private int $crawlDelay = 0;
    private array $generatedSitemaps = [];
    private array $graphEdges = [];
    private array $globalVisited = [];

    // === File system paths ===
    private string $templateDir = __DIR__ . '/assets/templates';
    private string $cacheDir = __DIR__ . '/cache';
    private string $logDir = __DIR__ . '/logs';

    // === Filter configuration ===
    private array $excludeExtensions = [];
    private array $excludePatterns = [];
    private array $includeOnlyPatterns = [];
    private array $priorityPatterns = [];
    private array $changefreqPatterns = [];


    // Constructor to initialize crawler options from CLI or GET parameters
    /**
     * Initializes the SitemapGenerator with given CLI or GET options.
     * Sets domain(s), crawling behavior, filters, and output parameters.
     *
     * @param array $options CLI or GET parameters to configure the crawler
     */
    public function __construct(array $options)
    {
        // === Basic configuration ===
        $this->startUrl = rtrim($options['url'], '/');
        $this->startUrls = is_array($options['url'])
            ? array_map(fn($u) => rtrim(trim($u), '/'), $options['url'])
            : array_map(fn($u) => rtrim(trim($u), '/'), explode(',', $options['url']));
        $this->maxDepth = isset($options['depth']) ? (int)$options['depth'] : 3;
        $this->userAgent = $options['agent'] ?? 'SitemapGenerator';
        $this->outputPath = $options['output'] ?? (__DIR__ . '/sitemap.xml');
        $this->threadCount = isset($options['threads']) ? max(1, (int)$options['threads']) : 10;

        // === Boolean flags from CLI / GET
        $this->useGzip = array_key_exists('gzip', $options);
        $this->resume = array_key_exists('resume', $options);
        $this->ignoreMeta = array_key_exists('ignoremeta', $options);
        $this->pretty = array_key_exists('prettyxml', $options);
        $this->respectRobots = array_key_exists('respectrobots', $options);
        $this->pingSearchEngines = array_key_exists('ping', $options);
        $this->debug = array_key_exists('debug', $options);
        $this->allowFiles = array_key_exists('allowfiles', $options);
        $this->useFilters = array_key_exists('filters', $options);
        $this->usePriorityRules = array_key_exists('priorityrules', $options);
        $this->useChangefreqRules = array_key_exists('changefreqrules', $options);
        $this->resetCache = array_key_exists('resetcache', $options);
        $this->resetLog = array_key_exists('resetlog', $options);
        $this->splitBySite = array_key_exists('splitbysite', $options);
        $this->exportGraph = array_key_exists('graphmap', $options);
        $this->fromEmail = $options['from'] ?? null;
        $this->publicBase = $options['publicbase'] ?? null;

        // === Optional
        $this->emailLog = $options['email'] ?? null;

        // === Initial queue
        $this->queue[] = [$this->startUrl, 0];

        // === Load filter configuration if enabled
        $filterFile = __DIR__ . '/config/filter.json';
        if (!file_exists($filterFile)) {
            $this->addLog("filter.json does not exist.", 'error');
        } else {
            $filters = json_decode(file_get_contents($filterFile), true);

            if ($this->useFilters) {
                $this->excludeExtensions = $filters['excludeExtensions'] ?? [];
                $this->excludePatterns = $filters['excludePatterns'] ?? [];
                $this->includeOnlyPatterns = $filters['includeOnlyPatterns'] ?? [];
            }

            if ($this->usePriorityRules) {
                $this->priorityPatterns = $filters['priorityPatterns'] ?? [];
            }

            if ($this->useChangefreqRules) {
                $this->changefreqPatterns = $filters['changefreqPatterns'] ?? [];
            }
        }

        // === Parse domain base
        $parsedUrl = parse_url($this->startUrl);
        $this->host = $parsedUrl['host'] ?? '';
        $this->scheme = $parsedUrl['scheme'] ?? 'https';

        // === Prepare filesystem directories
        if (!file_exists($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
        if (!file_exists($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
    }


    // Main method to start crawling process and generate the sitemap
    /**
     * Starts the crawling process and builds the sitemap(s).
     * Handles crawling logic, respects robots.txt, and generates logs.
     */
    public function run(): void
    {
        // If the --resetlog option is enabled, delete previous log files
        if ($this->resetLog) {
            // Define the list of log files to delete
            $logFiles = [
                "{$this->logDir}/crawl_log.txt",
                "{$this->logDir}/health_report.txt"
            ];

            // Loop through each log file
            foreach ($logFiles as $file) {
                // If the log file exists, delete it
                if (file_exists($file)) {
                    unlink($file);

                    // If debug mode is active, record the deletion in the log
                    if ($this->debug) {
                        $this->addLog("Log file removed: " . basename($file), 'info');
                    }
                }
            }
        }

        // Define path to the visited cache file
        $visitedCache = "{$this->cacheDir}/visited.json";

        // If --resetcache is enabled and the cache file exists, delete it
        if ($this->resetCache && file_exists($visitedCache)) {
            // Count number of cached URLs before deleting
            $count = 0;
            $cachedData = json_decode(file_get_contents($visitedCache), true);
            if (is_array($cachedData)) {
                $count = count($cachedData);
            }

            // Remove the old crawl state
            unlink($visitedCache);

            // Log reset action if debugging is enabled
            if ($this->debug) {
                $this->addLog("Cache cleared before crawling. ($count entries removed)", 'info');
            }
        }

        $this->globalVisited = [];

        foreach ($this->startUrls as $startUrl) {
            // Set current target domain
            $this->startUrl = $startUrl;
            $this->queue = [[$startUrl, 0]];
            $this->visited = []; // Reset visited per domain
            $this->log = [];
            $this->addLog("Crawling: $startUrl", 'info');

            // Parse base domain
            $parsedUrl = parse_url($startUrl);
            $this->host = $parsedUrl['host'] ?? '';
            $this->scheme = $parsedUrl['scheme'] ?? 'https';
            $safeHost = preg_replace('/[^a-z0-9\-\.]/i', '_', $this->host);

            // Determine output path for this domain
            if ($this->splitBySite) {
                // If user defined --output=/some/dir/sitemap.xml → adjust per domain
                if (!empty($this->outputPath)) {
                    $ext = pathinfo($this->outputPath, PATHINFO_EXTENSION);
                    $base = preg_replace('/\\.' . $ext . '$/', '', $this->outputPath);
                    $this->outputPath = $base . '-' . $safeHost . '.' . $ext;
                } else {
                    // Default fallback
                    $this->outputPath = __DIR__ . "/sitemaps/{$safeHost}.xml";
                }
            }

            // Ensure output directory exists
            $dir = dirname($this->outputPath);
            if (!file_exists($dir)) {
                if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                    $this->addLog("Failed to create output directory: $dir", 'error');
                    continue;
                }
            }

            // Track sitemap metadata if generating a sitemap index later
            if ($this->splitBySite) {
                $this->generatedSitemaps[] = [
                    'file' => basename($this->outputPath),
                    'lastmod' => date('Y-m-d'),
                    'base' => "{$this->scheme}://{$this->host}/" . trim(str_replace(__DIR__, '', dirname($this->outputPath)), '/')
                ];
            }

            $this->addLog("Saving sitemap to: {$this->outputPath}", 'info');

            // Start crawl process for this domain
            $this->runCrawl();

            // ✅ Combine visited from this run
            if (!$this->splitBySite) {
                $this->globalVisited += $this->visited;
            }
        }

        $this->finalizeAfterRun();
    }


    /**
     * Executes the core crawling logic for a single start URL.
     */
    private function runCrawl(): void
    {
        $visitedCache = "{$this->cacheDir}/visited.json";

        // Resume from cache if enabled
        if ($this->resume && file_exists($visitedCache)) {
            $this->visited = json_decode(file_get_contents($visitedCache), true) ?? [];
            $this->addLog("Resuming crawl from cache.", 'info');
        }

        // Load robots.txt if needed
        if ($this->respectRobots) {
            $this->loadRobotsTxt();
        }

        $mh = curl_multi_init();

        while (!empty($this->queue)) {
            $curlHandles = [];
            $batch = array_splice($this->queue, 0, $this->threadCount);

            foreach ($batch as [$url, $depth]) {
                $canonicalUrl = $this->canonicalizeUrl($url);

                if (isset($this->visited[$canonicalUrl]) || $depth > $this->maxDepth || $this->shouldExcludeUrl($canonicalUrl)) {
                    if ($this->shouldExcludeUrl($canonicalUrl)) {
                        $this->addLog("$canonicalUrl excluded by filter", 'info');
                    }
                    continue;
                }

                if ($this->isBlockedByRobots($url)) {
                    $this->addLog("Blocked $url", 'robots.txt');
                    $this->visited[$canonicalUrl] = true;
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

            // Execute all curl handles in parallel
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            foreach ($curlHandles as [$ch, $url, $depth]) {
                $html = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if (!$html) {
                    $this->addLog("$url", 'empty response');
                    continue;
                }

                $canonicalUrl = $this->canonicalizeUrl($url);
                $this->visited[$canonicalUrl] = true;
                $this->addLog("$canonicalUrl", 'indexed');

                if (!$this->splitBySite) {
                    $this->globalVisited[$canonicalUrl] = true;
                }

                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

                $this->graphNodes[] = [
                    'id' => $url,
                    'label' => $url,
                    'level' => $depth,
                    'status' => $httpCode,
                    'extension' => $extension ?: 'html'  // fallback if no extension
                ];

                // Parse <base href>
                $baseHref = null;
                if (preg_match('/<base\s+href=["\']([^"\']+)["\']/i', $html, $baseMatch)) {
                    $baseHref = trim($baseMatch[1]);
                    if ($this->debug) {
                        $this->addLog("Found: $baseHref", 'base href');
                    }
                }

                // Parse <meta name="robots">
                $blockedByMeta = false;
                if (!$this->ignoreMeta && preg_match_all('/<meta[^>]+name=["\']robots["\'][^>]*>/i', $html, $metaTags)) {
                    foreach ($metaTags[0] as $meta) {
                        if (preg_match('/content=["\']([^"\']+)["\']/', $meta, $match)) {
                            $metaContent = strtolower(trim($match[1]));
                            if ($this->debug) {
                                $this->addLog("robots => '$metaContent'", 'meta found');
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
                    $this->addLog("$url", 'meta blocked');
                    $this->visited[$canonicalUrl] = true;
                    continue;
                }

                file_put_contents($visitedCache, json_encode($this->visited));

                // Extract links and queue them
                preg_match_all('/<a\s+(?:[^>]*?\s+)?href=["\']([^"\']+)["\']/i', $html, $matches);
                foreach ($matches[1] as $link) {
                    $normalizedLink = $this->normalizeUrl($link, $baseHref ?? $url);
                    $canonicalLink = $this->canonicalizeUrl($normalizedLink);

                    if (!$canonicalLink || isset($this->visited[$canonicalLink])) {
                        continue;
                    }

                    $this->graphEdges[] = ['from' => $url, 'to' => $canonicalLink]; // Log internal link structure

                    // Check for unwanted file types
                    $ext = strtolower(pathinfo(parse_url($canonicalLink, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                    if (!$this->allowFiles && in_array($ext, $this->excludeExtensions)) {
                        if ($this->debug) {
                            $this->addLog("$canonicalLink", 'skipped extension');
                        }
                        continue;
                    }

                    // Same domain only
                    $linkHost = parse_url($canonicalLink, PHP_URL_HOST);
                    if ($linkHost && ($linkHost === $this->host || preg_replace('/^www\./', '', $linkHost) === preg_replace('/^www\./', '', $this->host))) {
                        $this->queue[] = [$canonicalLink, $depth + 1];
                        if ($this->debug) {
                            $this->addLog("$canonicalLink", 'queue');
                        }
                    }
                }

                if ($this->crawlDelay > 0) {
                    sleep($this->crawlDelay);
                }
            }
        }

        curl_multi_close($mh);
    }


    /**
     * Finalize the crawl process by exporting data and sending notifications.
     * This includes exporting the graph (if enabled) and sending the global log as an email attachment.
     */
    private function finalizeAfterRun(): void
    {
        // ✅ If NOT split by site, generate combined sitemap
        if (!$this->splitBySite) {
            $this->visited = $this->globalVisited;
            $this->createSitemap();

            // Add info to both current and global log
            $this->addLog("Sitemap successfully created with " . count($this->visited) . " URLs on " . date('Y-m-d H:i:s') . ".", 'info');
        }

        // 🔧 Optional: Generate sitemap index for multiple sites
        if ($this->splitBySite && count($this->generatedSitemaps) > 0) {
            $this->createSitemapIndex();
        }

        // ✅ Add global completion log
        $this->addLog("✅ Crawl finished for " . count($this->startUrls) . " domain(s) at " . date('Y-m-d H:i:s') . ".", 'info');

        // ✅ Health Report from globalLog
        $statusSummary = [
            'blocked_robots' => 0,
            'blocked_meta' => 0,
            'http_errors' => 0,
            'redirects' => 0,
            'slow_pages' => 0,
        ];

        foreach ($this->globalLog as $entry) {
            $msg = $entry['message'] ?? '';

            if (str_contains($msg, '[robots.txt BLOCKED]')) $statusSummary['blocked_robots']++;
            if (str_contains($msg, '[META BLOCKED]')) $statusSummary['blocked_meta']++;
            if (preg_match('/\[HTTP ([45][0-9]{2})\]/', $msg)) $statusSummary['http_errors']++;
            if (str_contains($msg, '[REDIRECT]')) $statusSummary['redirects']++;
            if (preg_match('/\((\d+\.\d+)s\)/', $msg, $match) && (float)$match[1] > 3.0) {
                $statusSummary['slow_pages']++;
            }
        }

        // 📋 Save health report
        $healthReport = ["[HEALTH CHECK]"];
        foreach ($statusSummary as $key => $count) {
            $healthReport[] = strtoupper($key) . ': ' . $count;
        }

        // 🔔 Ping search engines
        if ($this->pingSearchEngines) {
            $this->pingSearchEngines();
        }

        // 📧 Send email with global log as attachment
        if (!empty($this->emailLog) && filter_var($this->emailLog, FILTER_VALIDATE_EMAIL)) {
            $logFile = "{$this->logDir}/crawl_report_" . date('Ymd_His') . ".txt";
            file_put_contents($logFile, implode("\n", $this->flattenLog($this->globalLog)));

            $boundary = md5(uniqid("boundary_", true));
            $subject = "Sitemap Crawl Report";
            $headers = "From: " . ($this->fromEmail ?: 'crawler@localhost') . "\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"";

            $message = "--$boundary\r\n";
            $message .= "Content-Type: text/plain; charset=\"utf-8\"\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= "Sitemap crawl completed. See the attached log file.\r\n\r\n";

            $fileContent = chunk_split(base64_encode(file_get_contents($logFile)));
            $message .= "--$boundary\r\n";
            $message .= "Content-Type: text/plain; name=\"" . basename($logFile) . "\"\r\n";
            $message .= "Content-Disposition: attachment; filename=\"" . basename($logFile) . "\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= $fileContent . "\r\n";
            $message .= "--$boundary--";

            $sent = mail($this->emailLog, $subject, $message, $headers);

            if ($sent) {
                $this->addLog("Report sent to: {$this->emailLog}", 'mail');
            } else {
                $this->addLog("Failed to send email to: {$this->emailLog}", 'mail error');
            }
        }

        // ✅ Create log files
        $this->exportJsonLog();
        $this->exportHtmlLog();
    }


    // Loads and parses the site's robots.txt to respect crawl rules and delays.
    /**
     * Loads and parses the robots.txt file of the current domain.
     * Extracts disallowed paths and optional crawl-delay for the configured user-agent.
     */
    private function loadRobotsTxt(): void
    {
        $robotsUrl = "{$this->scheme}://{$this->host}/robots.txt";
        $robotsContent = @file_get_contents($robotsUrl);

        if ($robotsContent === false) {
            $this->addLog("robots.txt not found or unreadable at $robotsUrl", 'robots');
            return;
        }

        if ($this->debug) {
            $this->addLog("Successfully fetched robots.txt from $robotsUrl", 'robots');
        }

        // Extract crawl-delay
        if (preg_match('/crawl-delay:\s*(\d+)/i', $robotsContent, $match)) {
            $this->crawlDelay = (int)$match[1];
            $this->addLog("Crawl-delay detected: {$this->crawlDelay}s", 'robots');
        }

        $lines = explode("\n", strtolower($robotsContent));
        $currentAgent = null;
        $agents = [];

        foreach ($lines as $rawLine) {
            $line = is_string($rawLine)
                ? trim(preg_replace('/\s*#.*$/', '', $rawLine)) // Remove comments
                : '';

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'user-agent:')) {
                $currentAgent = trim(substr($line, 11));
                if (!isset($agents[$currentAgent])) {
                    $agents[$currentAgent] = [];
                }
            } elseif ($currentAgent && (str_starts_with($line, 'disallow:') || str_starts_with($line, 'allow:'))) {
                [$directive, $path] = explode(':', $line, 2);
                $agents[$currentAgent][] = [trim($directive), trim($path)];
            }
        }

        // Match directives for current user-agent or fallback to '*'
        $ua = strtolower($this->userAgent);
        $this->disallowedPaths = $agents[$ua] ?? $agents['*'] ?? [];

        if ($this->debug) {
            $this->addLog("Loaded " . count($this->disallowedPaths) . " rules for agent: {$ua}", 'robots');
        }
    }


    // Checks if a specific URL is blocked by the loaded robots.txt rules.
    /**
     * Determines if a given URL is blocked by robots.txt rules.
     *
     * @param string $url The URL to evaluate
     * @return bool True if disallowed, false otherwise
     */
    private function isBlockedByRobots(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $matchedRule = null;
        $matchedLength = -1;

        foreach ($this->disallowedPaths as [$directive, $rulePath]) {
            // Match rule if path starts with rulePath or rulePath is empty
            if ($rulePath === '' || str_starts_with($path, $rulePath)) {
                if (strlen($rulePath) > $matchedLength) {
                    $matchedRule = [$directive, $rulePath];
                    $matchedLength = strlen($rulePath);
                }
            }
        }

        // Log matched rule if debugging
        if ($this->debug && $matchedRule) {
            $this->addLog("$path matched {$matchedRule[0]}: {$matchedRule[1]}", 'robots check');
        }

        // Return true if the longest matched rule was a disallow
        return $matchedRule && $matchedRule[0] === 'disallow';
    }


    /**
     * Resolves and normalizes a relative or absolute URL against a given base URL.
     * Filters out mailto:, javascript:, tel:, fragments and validates final result.
     *
     * @param string $href Raw href value from <a> tag
     * @param string $base Base URL to resolve relative paths
     * @return string|null Normalized absolute URL, or null if invalid
     */
    private function normalizeUrl(string $href, string $base): ?string
    {
        // Remove fragments like #section and trim
        if (!is_string($href) || trim($href) === '') {
            return null;
        }

        $href = trim($href);
        $fragmentFree = strtok($href, '#');

        if (!is_string($fragmentFree) || trim($fragmentFree) === '') {
            return null;
        }

        $href = trim($fragmentFree);

        // Ignore special schemes
        if (
            str_starts_with($href, 'mailto:') ||
            str_starts_with($href, 'javascript:') ||
            str_starts_with($href, 'tel:')
        ) {
            return null;
        }

        // Already absolute URL
        if (parse_url($href, PHP_URL_SCHEME)) {
            $absolute = rtrim($href, '/');
            return filter_var($absolute, FILTER_VALIDATE_URL) ? $absolute : null;
        }

        // Resolve relative path against base
        $baseParts = parse_url($base);
        if (!$baseParts || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $basePath = $baseParts['path'] ?? '/';

        if (str_starts_with($href, '/')) {
            $absolute = "$scheme://$host" . rtrim($href, '/');
        } else {
            $dir = rtrim(dirname($basePath), '/');
            $path = "$dir/$href";
            $path = preg_replace('#/+#', '/', $path); // collapse slashes
            $absolute = "$scheme://$host$path";
        }

        // Final URL validation
        return filter_var($absolute, FILTER_VALIDATE_URL) ? $absolute : null;
    }


    // Builds and saves the final sitemap XML file, optionally gzipped.
    /**
     * Pings search engines (Google, Bing, Yandex) with the generated sitemap URL.
     * Automatically detects the correct sitemap path and supports gzip + custom output paths.
     * Logs are written to both the domain log and the global log for inclusion in reports.
     */
    private function pingSearchEngines(): void
    {
        // Determine sitemap URL based on whether splitBySite is enabled
        if ($this->splitBySite) {
            // Use sitemap_index.xml when multiple domains are crawled
            $base = $this->getBaseUrlFromFirstDomain();
            $sitemapUrl = rtrim($base, '/') . '/sitemap_index.xml';
            $message = "[PING] Notifying search engines using sitemap index: $sitemapUrl";
        } else {
            // Try to resolve sitemap URL based on outputPath
            $localPath = realpath($this->outputPath . ($this->useGzip ? '.gz' : ''));
            $baseHost = parse_url($this->startUrl, PHP_URL_HOST);
            $baseScheme = parse_url($this->startUrl, PHP_URL_SCHEME) ?: 'https';

            // Optional: allow override via --publicbase
            $publicBase = $this->publicBase ?? null;

            if ($publicBase) {
                // If provided manually (recommended)
                $sitemapUrl = rtrim($publicBase, '/') . '/' . basename($this->outputPath);
                if ($this->useGzip) {
                    $sitemapUrl .= '.gz';
                }
            } elseif ($localPath && isset($_SERVER['DOCUMENT_ROOT'])) {
                // Try to build public URL from local file path
                $relativePath = str_replace(realpath($_SERVER['DOCUMENT_ROOT']), '', $localPath);
                $sitemapUrl = rtrim("$baseScheme://$baseHost", '/') . $relativePath;
            } else {
                // Fallback assumption
                $sitemapUrl = rtrim($this->startUrl, '/') . '/sitemap.xml';
                if ($this->useGzip) {
                    $sitemapUrl .= '.gz';
                }
            }

            $message = "[PING] Notifying search engines using sitemap URL: $sitemapUrl";
        }

        // Log to both domain and global logs
        $this->addLog("$message", 'ping');
        $this->log[] = $message;

        // List of search engine ping endpoints
        $engines = [
            'Yandex' => 'https://webmaster.yandex.com/ping?sitemap=' . urlencode($sitemapUrl)
        ];

        // Loop through each and perform HTTP GET
        foreach ($engines as $name => $url) {
            $context = stream_context_create(['http' => ['timeout' => 10]]);
            $resp = @file_get_contents($url, false, $context);

            if ($resp !== false) {
                $logEntry = "[Ping][$name] Success – Response Length: " . strlen($resp);
            } else {
                $error = error_get_last();
                $logEntry = "[Ping][$name] Failed – " . ($error['message'] ?? 'Unknown error');
            }

            $this->addLog("$logEntry", 'ping');
        }
    }



    /**
     * Normalizes and canonicalizes a URL for consistent comparison and indexing.
     * Removes default filenames like index.html or index.php, and trailing slashes.
     *
     * @param string|null $url The URL to normalize
     * @return string Canonicalized absolute URL, or empty string if invalid
     */

    private function canonicalizeUrl(?string $url): string
    {
        // Reject empty or non-string inputs
        if (!is_string($url) || trim($url) === '') {
            return '';
        }

        $url = trim($url);

        // Parse the URL to extract components
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return $url;
        }

        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host']);
        $path = $parsed['path'] ?? '/';

        // Remove trailing index files (e.g., /index.html or /index.php)
        $path = preg_replace('#/index\.(html?|php)$#i', '', $path);

        // Normalize trailing slash
        $path = rtrim($path, '/');

        // Reconstruct the canonical URL
        return $scheme . '://' . $host . ($path !== '' ? $path : '');
    }



    // Determines sitemap priority based on URL patterns.
    /**
     * Determines the priority value for a given URL based on configured patterns.
     *
     * @param string $url The URL to evaluate
     * @return string Priority value as string (e.g., '0.8', '0.2', '0.5')
     */
    private function getPriorityForUrl(string $url): string
    {
        // Check high priority patterns
        foreach ($this->priorityPatterns['high'] ?? [] as $pattern) {
            if (str_contains($url, $pattern)) {
                if ($this->debug) {
                    $this->addLog("'$pattern' matched $url", 'match prio high');
                }
                return '0.8';
            }

            if ($this->debug) {
                $this->addLog("Testing '$pattern' on $url", 'check prio high');
            }
        }

        // Check low priority patterns
        foreach ($this->priorityPatterns['low'] ?? [] as $pattern) {
            if (str_contains($url, $pattern)) {
                if ($this->debug) {
                    $this->addLog("'$pattern' matched $url", 'match prio low');
                }
                return '0.2';
            }

            if ($this->debug) {
                $this->addLog("Testing '$pattern' on $url", 'check prio low');
            }
        }

        // Default priority
        return '0.5';
    }



    // Determines sitemap change frequency based on URL patterns.
    /**
     * Determines the change frequency for a given URL based on configured patterns.
     *
     * @param string $url The URL to evaluate
     * @return string Change frequency value (e.g. 'daily', 'monthly', 'weekly')
     */
    private function getChangefreqForUrl(string $url): string
    {
        // Check daily patterns
        foreach ($this->changefreqPatterns['daily'] ?? [] as $pattern) {
            if (str_contains($url, $pattern)) {
                if ($this->debug) {
                    $this->addLog("'$pattern' matched $url", 'match freq daily');
                }
                return 'daily';
            }

            if ($this->debug) {
                $this->addLog("Testing '$pattern' on $url", 'check freq daily');
            }
        }

        // Check monthly patterns
        foreach ($this->changefreqPatterns['monthly'] ?? [] as $pattern) {
            if (str_contains($url, $pattern)) {
                if ($this->debug) {
                    $this->addLog("'$pattern' matched $url", 'match freq monthly');
                }
                return 'monthly';
            }

            if ($this->debug) {
                $this->addLog("Testing '$pattern' on $url", 'check freq monthly');
            }
        }

        // Fallback default
        return 'weekly';
    }


    // Applies user-defined filtering rules to skip unwanted URLs.
    /**
     * Determines whether a given URL should be excluded based on extension, exclude/include patterns.
     * Supports simple wildcards in patterns (e.g., *.pdf, /private/*).
     *
     * @param string $url The absolute URL to evaluate
     * @return bool True if the URL should be excluded, false otherwise
     */
    private function shouldExcludeUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Match extension
        if ($ext && in_array($ext, $this->excludeExtensions, true)) {
            if ($this->debug) {
                $this->addLog("$ext matched for $url", 'exclude extension');
            }
            return true;
        }

        // Match against exclude patterns (wildcards or plain substrings)
        foreach ($this->excludePatterns as $pattern) {
            if ($this->wildcardMatch($url, $pattern)) {
                if ($this->debug) {
                    $this->addLog("'$pattern' matched for $url", 'exclude pattern');
                }
                return true;
            }
        }

        // If include-only patterns are set, allow only matching URLs
        if (!empty($this->includeOnlyPatterns)) {
            foreach ($this->includeOnlyPatterns as $pattern) {
                if ($this->wildcardMatch($url, $pattern)) {
                    if ($this->debug) {
                        $this->addLog("'$pattern' matched $url", 'include match');
                    }
                    return false;
                }
            }

            if ($this->debug) {
                $this->addLog("No includeOnlyPattern matched $url", 'exclude');
            }
            return true;
        }

        return false;
    }


    /**
     * Matches a string against a pattern with optional wildcards (*).
     *
     * @param string $subject The string to test (e.g. URL)
     * @param string $pattern The pattern (can include * wildcards)
     * @return bool True if match, false otherwise
     */
    private function wildcardMatch(string $subject, string $pattern): bool
    {
        // Escape regex characters except '*', then convert '*' to '.*'
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
        return (bool) preg_match($regex, $subject);
    }

    /**
     *
     * Determine the base URL from the first domain in the startUrls array
     */
    private function getBaseUrlFromFirstDomain(): string
    {
        $first = $this->startUrls[0] ?? '';
        $parsed = parse_url($first);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? 'localhost';

        return "{$scheme}://{$host}";
    }

    /**
     * Generates one or more sitemap files from the visited URLs.
     * Automatically splits into multiple files if limit is reached.
     * Supports pretty-printing and optional gzip output.
     */
    private function createSitemap(): void
    {
        if (empty($this->visited)) {
            $this->addLog("No URLs to write to sitemap file", 'warning');
            return;
        }

        $urls = array_keys($this->visited);
        $chunks = array_chunk($urls, $this->sitemapLimit);
        $multi = count($chunks) > 1;
        $index = 1;

        foreach ($chunks as $chunk) {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = $this->pretty;

            $urlset = $dom->createElement('urlset');
            $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $dom->appendChild($urlset);

            foreach ($chunk as $url) {
                $priority = $this->getPriorityForUrl($url);
                $freq = $this->getChangefreqForUrl($url);

                if ($this->debug) {
                    $this->addLog("$url => $priority", 'priority debug');
                    $this->addLog("$url => $freq", 'freq debug');
                }

                $urlEl = $dom->createElement('url');
                $urlEl->appendChild($dom->createElement('loc', htmlspecialchars($url)));
                $urlEl->appendChild($dom->createElement('lastmod', date('Y-m-d')));
                $urlEl->appendChild($dom->createElement('changefreq', $freq));
                $urlEl->appendChild($dom->createElement('priority', $priority));
                $urlset->appendChild($urlEl);
            }

            // If multiple files needed, suffix them
            $suffix = $multi ? "-$index" : '';
            $filename = preg_replace('/\.xml$/', "$suffix.xml", $this->outputPath);

            // Save the sitemap (gzipped or plain)
            if ($this->useGzip) {
                $gzPath = $filename . '.gz';
                if (file_put_contents($gzPath, gzencode($dom->saveXML())) !== false) {
                    $this->addLog("GZIP sitemap saved to: $gzPath", 'sitemap');
                } else {
                    $this->addLog("Failed to write GZIP sitemap: $gzPath", 'error');
                }
            } else {
                if (file_put_contents($filename, $dom->saveXML()) !== false) {
                    $this->addLog("Sitemap saved to: $filename", 'sitemap');
                } else {
                    $this->addLog("Failed to write sitemap: $filename", 'error');
                }
            }

            $index++;
        }

        if ($multi) {
            $this->addLog("Sitemap was split into " . count($chunks) . " parts.", 'info');
        }

        if ($this->debug) {
            $this->addLog("Using dynamic priority: " . ($this->usePriorityRules ? 'yes' : 'no'), 'sitemap');
            $this->addLog("Using dynamic changefreq: " . ($this->useChangefreqRules ? 'yes' : 'no'), 'sitemap');
        }
    }


    /**
     * Generate a sitemap_index.xml that includes all generated sitemaps
     * This helps search engines discover multiple sitemaps from a single entry point
     */
    private function createSitemapIndex(): void
    {
        if (empty($this->visited)) {
            $this->addLog("No URLs to write to sitemap file", 'info');
            return;
        }

        $urls = array_keys($this->visited);
        $chunks = array_chunk($urls, $this->sitemapLimit);
        $multi = count($chunks) > 1;
        $index = 1;
        $writtenFiles = [];

        foreach ($chunks as $chunk) {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;

            $urlset = $dom->createElement('urlset');
            $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $dom->appendChild($urlset);

            foreach ($chunk as $url) {
                // Log and calculate priority/frequency
                $this->addLog("$url => " . urlencode($url), 'clean url');
                $priority = $this->getPriorityForUrl($url);
                $freq = $this->getChangefreqForUrl($url);

                if ($this->debug) {
                    $this->addLog("$url => $freq", 'freq debug');
                    $this->addLog("$url => $priority", 'priority debug');
                }

                // Build <url> entry
                $urlEl = $dom->createElement('url');
                $urlEl->appendChild($dom->createElement('loc', htmlspecialchars($url)));
                $urlEl->appendChild($dom->createElement('lastmod', date('Y-m-d')));
                $urlEl->appendChild($dom->createElement('changefreq', $freq));
                $urlEl->appendChild($dom->createElement('priority', $priority));
                $urlset->appendChild($urlEl);
            }

            // Define file name (append -1, -2 if multiple parts)
            $suffix = $multi ? "-$index" : '';
            $outputFile = preg_replace('/\\.xml$/', "$suffix.xml", $this->outputPath);
            $writtenFiles[] = $outputFile;

            // Write XML to file (optionally gzip)
            $xmlOutput = $dom->saveXML();
            if ($this->useGzip) {
                $gzPath = $outputFile . '.gz';
                if (file_put_contents($gzPath, gzencode($xmlOutput)) !== false) {
                    $this->addLog("GZIP sitemap saved to: $gzPath", 'sitemap');
                } else {
                    $this->addLog("Failed to write GZIP sitemap: $gzPath", 'error');
                }
            } else {
                if (file_put_contents($outputFile, $xmlOutput) !== false) {
                    $this->addLog("Sitemap saved to: $outputFile", 'sitemap');
                } else {
                    $this->addLog("Failed to write sitemap: $outputFile", 'error');
                }
            }

            $index++;
        }

        // Optional: generate sitemap index if multiple files
        if ($multi) {
            $indexDom = new DOMDocument('1.0', 'UTF-8');
            $indexDom->formatOutput = true;

            $sitemapIndex = $indexDom->createElement('sitemapindex');
            $sitemapIndex->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $indexDom->appendChild($sitemapIndex);

            foreach ($writtenFiles as $file) {
                $relativeUrl = $this->startUrl . '/' . basename($file);
                if ($this->useGzip) $relativeUrl .= '.gz';

                $sitemap = $indexDom->createElement('sitemap');
                $sitemap->appendChild($indexDom->createElement('loc', $relativeUrl));
                $sitemap->appendChild($indexDom->createElement('lastmod', date('Y-m-d')));
                $sitemapIndex->appendChild($sitemap);
            }

            $indexFile = preg_replace('/\\.xml$/', '_index.xml', $this->outputPath);
            file_put_contents($indexFile, $indexDom->saveXML());
            $this->addLog("Index sitemap created: $indexFile", 'sitemap');
        }

        if ($this->debug) {
            $this->addLog("Using dynamic priority: " . ($this->usePriorityRules ? 'yes' : 'no'), 'sitemap');
            $this->addLog("Using dynamic changefreq: " . ($this->useChangefreqRules ? 'yes' : 'no'), 'sitemap');
        }
    }

    private function exportGraphHtml(): void
    {
        $templatePath = "{$this->templateDir}/crawl_map_template_dark.html";

        if (!file_exists($templatePath)) {
            $this->addLog("Graph template not found: crawl_map_template.html", 'error');
            return;
        }

        $html = file_get_contents($templatePath);
        file_put_contents("{$this->logDir}/crawl_map.html", $html);
    }

    private function exportGraphJson(): void
    {
        $nodes = [];
        $unique = [];

        foreach ($this->graphEdges as $edge) {
            $from = $edge['from'];
            $to = $edge['to'];

            // Avoid duplicate node entries
            if (!isset($unique[$from])) {
                $nodes[] = ['id' => $from, 'label' => parse_url($from, PHP_URL_PATH)];
                $unique[$from] = true;
            }
            if (!isset($unique[$to])) {
                $nodes[] = ['id' => $to, 'label' => parse_url($to, PHP_URL_PATH)];
                $unique[$to] = true;
            }
        }

        $graph = [
            'nodes' => $nodes,
            'edges' => $this->graphEdges
        ];

        file_put_contents("{$this->logDir}/crawl_graph.json", json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }


    /**
     * Adds a log entry with timestamp and level.
     *
     * @param string $message Log message
     * @param string $level   Log level: info, warning, error, debug, success
     */
    private function addLog(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $entry = [
            'time' => $timestamp,
            'level' => strtoupper($level),
            'message' => $message
        ];
        $this->log[] = $entry;
        $this->globalLog[] = $entry;
    }


    /**
     * Exports the crawl log as JSON for machine-readable access.
     */
    private function exportJsonLog(): void
    {
        $json = json_encode($this->log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents("{$this->logDir}/crawl_log.json", $json);
    }


    /**
     * Exports the crawl log as a styled HTML report using a template.
     */
    private function exportHtmlLog(): void
    {
        $template = "{$this->templateDir}/log_template_dark.html";
        $target = "{$this->logDir}/crawl_log.html";

        if (!file_exists($template)) {
            $this->addLog("Log HTML template not found: $template", 'warning');
            return;
        }

        $logData = json_encode($this->log, JSON_UNESCAPED_SLASHES);
        $html = file_get_contents($template);
        $html = str_replace('{{LOG_JSON}}', "const logEntries = $logData;", $html);
        file_put_contents($target, $html);

        $this->addLog("HTML log exported to: $target", 'info');
    }

    /**
     * Flattens and groups log entries based on their log level.
     *
     * Each log is sorted into a named section based on its original 'level' key.
     * Supports custom log levels like 'indexed', 'skipped', 'meta blocked', etc.
     *
     * @param array $log
     * @return array
     */
    private function flattenLog(array $log): array
    {
        $groups = [
            'SUMMARY'  => [],
            'INDEXED'  => [],
            'BLOCKED'  => [],
            'SKIPPED'  => [],
            'ERRORS'   => [],
            'QUEUED'   => [],
            'SITEMAP'  => [],
            'GENERAL'  => [],
        ];

        foreach ($log as $entry) {
            $time = $entry['time'] ?? '-';
            $level = strtolower($entry['level'] ?? 'info');
            $msg = $entry['message'] ?? '';
            $line = "[$time] [" . strtoupper($level) . "] $msg";

            switch ($level) {
                case 'indexed':
                    $groups['INDEXED'][] = $line;
                    break;
                case 'blocked':
                case 'meta blocked':
                case 'robots':
                    $groups['BLOCKED'][] = $line;
                    break;
                case 'skipped':
                case 'skipped extension':
                    $groups['SKIPPED'][] = $line;
                    break;
                case 'error':
                case 'errors':
                case 'mail error':
                    $groups['ERRORS'][] = $line;
                    break;
                case 'empty response':
                    $groups['EMPTY RESPONSE'][] = $line;
                    break;
                case 'queued':
                case 'queue':
                    $groups['QUEUED'][] = $line;
                    break;
                case 'sitemap':
                case 'ping':
                    $groups['SITEMAP'][] = $line;
                    break;
                case 'success':
                case 'summary':
                    $groups['SUMMARY'][] = $line;
                    break;
                default:
                    $groups['GENERAL'][] = $line;
                    break;
            }
        }

        $output = [];
        foreach ($groups as $section => $lines) {
            if (!empty($lines)) {
                $output[] = "==== $section ====";
                $output = array_merge($output, $lines);
                $output[] = '';
            }
        }

        return $output;
    }
}

// Define authorized hash key to protect script access
$authorizedHash = 'YOUR_SECRET_KEY';

// Detect CLI or Web context
$isCli = php_sapi_name() === 'cli';

// Gather CLI or GET parameters into $options
$options = $isCli
    ? getopt("", [
        "url:",
        "depth::",
        "threads::",
        "agent::",
        "output::",
        "prettyxml",
        "gzip",
        "splitbysite",
        "resume",
        "resetcache",
        "resetlog",
        "ignoremeta",
        "respectrobots",
        "allowfiles::",
        "filters",
        "priorityrules",
        "changefreqrules",
        "graphmap",
        "publicbase::",
        "email::",
        "from::",
        "debug",
        "ping",
        "key:",
    ])
    : [
        // Required
        'url'              => $_GET['url'] ?? null, // Main URL(s) to crawl (comma-separated)

        // Optional crawl settings
        'depth'            => isset($_GET['depth']) ? (int)$_GET['depth'] : null,
        'threads'          => isset($_GET['threads']) ? (int)$_GET['threads'] : null,
        'agent'            => $_GET['agent'] ?? null, // Custom user-agent

        // Output
        'output'           => $_GET['output'] ?? null, // Custom sitemap output path
        'prettyxml'        => isset($_GET['prettyxml']),
        'gzip'             => isset($_GET['gzip']),
        'splitbysite'      => isset($_GET['splitbysite']), // Generate separate sitemap per domain

        // Caching
        'resume'           => isset($_GET['resume']),
        'resetcache'       => isset($_GET['resetcache']),
        'resetlog'         => isset($_GET['resetlog']),

        // Behavior
        'ignoremeta'       => isset($_GET['ignoremeta']),
        'respectrobots'    => isset($_GET['respectrobots']),
        'allowfiles'       => !empty($_GET['allowfiles']), // Accept media/file links

        // Filtering & rules
        'filters'          => isset($_GET['filters']),
        'priorityrules'    => isset($_GET['priorityrules']),
        'changefreqrules'  => isset($_GET['changefreqrules']),

        // Graph / Map
        'graphmap'         => isset($_GET['graphmap']), // Enable JSON + HTML crawl map
        'publicbase'       => $_GET['publicbase'] ?? null, // Base URL for linking in HTML map

        // Mail
        'email'            => $_GET['email'] ?? null,
        'from'             => $_GET['from'] ?? null,

        // Other
        'debug'            => isset($_GET['debug']),
        'ping'             => isset($_GET['ping']),
        'key'              => $_GET['key'] ?? null,
    ];

// Optional debug: dump raw options
if (!empty($options['debug'])) {
    echo "[DEBUG] Raw options: " . json_encode($options, JSON_PRETTY_PRINT) . "\n";
}

// --- Validate required parameters ---
if (!isset($options['key']) || $options['key'] !== $authorizedHash) {
    die("Unauthorized. Valid 'key' parameter required.\n");
}

if (empty($options['url'])) {
    die("Missing required 'url' parameter.\n");
}

// --- Start Sitemap Generator ---
$generator = new SitemapGenerator($options);
$generator->run();