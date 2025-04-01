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
 * - Multithread (Default: 10)
 *
 * Usage (CLI):
 * php sitemap.php --url=https://yourdomain.com --key=YOUR_SECRET_KEY [options]
 *
 * Usage (Web):
 * sitemap.php?url=https://yourdomain.com&key=YOUR_SECRET_KEY&gzip&prettyxml
 *
 * Options:
 * Options:
 * --url=               Comma-separated list of domains to crawl (required)
 * --key=               Required hash key to authorize script execution
 * --depth=             Max crawl depth (default: 3)
 * --output=            Custom output path for sitemap (default: ./sitemap.xml)
 * --gzip               Save sitemap as gzip-compressed .xml.gz
 * --prettyxml          Format XML output to be human-readable
 * --resume             Resume crawl from previous session (uses cache/visited.json)
 * --resetcache         Delete crawl cache before start to force fresh crawl
 * --resetlog           Delete crawl_log.txt and health_report.txt before crawling
 * --email=             Email address to receive crawl log
 * --ping               Ping search engines after sitemap creation
 * --threads=           Number of parallel curl requests (default: 10)
 * --agent=             Custom user agent string (default: SitemapGenerator)
 * --ignoremeta         Ignore <meta name="robots"> rules
 * --respectrobots      Parse and obey robots.txt disallow and crawl-delay rules
 * --filters            Enable external URL filtering using config/filter.json
 * --priorityrules      Enable dynamic priority per URL based on patterns
 * --changefreqrules    Enable dynamic changefreq per URL based on patterns
 * --allowfiles         Allow crawling and indexing of files (e.g. PDF, DOCX)
 * --splitbysite        Write one sitemap per domain instead of a combined one (NEW)
 * --debug              Enable detailed debug logging (output to log file)
 *
 * Output:
 * - sitemap.xml (or .gz)
 * - logs/health_report.txt
 * - logs/crawl_log.txt
 * - cache/visited.json
 *
 * Author: Gilles Dumont (QIUBITS SARL)
 * Version: 1.4.0
 * License: MIT
 * Created: 2025-04-01
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
    private array $graphEdges = [];
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

    // === Internal working data ===
    private array $visited = [];

    private array $log = [];
    private array $queue = [];
    private string $host = '';
    private string $scheme = '';
    private array $disallowedPaths = [];
    private int $crawlDelay = 0;
    private array $generatedSitemaps = [];

    // === File system paths ===
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

        // === Optional
        $this->emailLog = $options['email'] ?? null;

        // === Initial queue
        $this->queue[] = [$this->startUrl, 0];

        // === Load filter configuration if enabled
        $filterFile = __DIR__ . '/config/filter.json';
        if (!file_exists($filterFile)) {
            $this->log[] = "[ERROR] filter.json does not exist.";
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
                        $this->log[] = "[RESET] Log file removed: " . basename($file);
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
                $this->log[] = "[RESET] Cache cleared before crawling. ($count entries removed)";
            }
        }

        foreach ($this->startUrls as $startUrl) {
            // Set current target domain
            $this->startUrl = $startUrl;
            $this->queue = [[$startUrl, 0]];
            $this->visited = []; // Reset visited per domain
            $this->log[] = "--- Crawling: $startUrl ---";

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
                    $this->log[] = "[ERROR] Failed to create output directory: $dir";
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

            $this->log[] = "[INFO] Saving sitemap to: {$this->outputPath}";

            // Start crawl process for this domain
            $this->runCrawl();
        }
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
            $this->log[] = "[RESUME] Resuming crawl from cache.";
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
                        $this->log[] = "[SKIPPED] $canonicalUrl excluded by filter";
                    }
                    continue;
                }

                if ($this->isBlockedByRobots($url)) {
                    $this->log[] = "[robots.txt BLOCKED] $url";
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
                    $this->log[] = "[EMPTY RESPONSE] $url";
                    continue;
                }

                $canonicalUrl = $this->canonicalizeUrl($url);
                $this->visited[$canonicalUrl] = true;
                $this->log[] = "[✓] $canonicalUrl";

                // Parse <base href>
                $baseHref = null;
                if (preg_match('/<base\s+href=["\']([^"\']+)["\']/i', $html, $baseMatch)) {
                    $baseHref = trim($baseMatch[1]);
                    if ($this->debug) {
                        $this->log[] = "[BASE HREF] Found: $baseHref";
                    }
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
                            $this->log[] = "[SKIPPED EXT] $canonicalLink";
                        }
                        continue;
                    }

                    // Same domain only
                    $linkHost = parse_url($canonicalLink, PHP_URL_HOST);
                    if ($linkHost && ($linkHost === $this->host || preg_replace('/^www\./', '', $linkHost) === preg_replace('/^www\./', '', $this->host))) {
                        $this->queue[] = [$canonicalLink, $depth + 1];
                        if ($this->debug) {
                            $this->log[] = "[QUEUE] $canonicalLink";
                        }
                    }
                }

                if ($this->crawlDelay > 0) {
                    sleep($this->crawlDelay);
                }
            }
        }

        curl_multi_close($mh);

        // After all domains are processed, create a sitemap index if needed
        if ($this->splitBySite && count($this->generatedSitemaps) > 0) {
            $this->createSitemapIndex();
            $this->log[] = "Sitemap successfully created with " . count($this->visited) . " URLs on " . date('Y-m-d H:i:s') . ".";
        }

        // Generate health check report
        $statusSummary = [
            'blocked_robots' => 0,
            'blocked_meta' => 0,
            'http_errors' => 0,
            'redirects' => 0,
            'slow_pages' => 0,
        ];

        foreach ($this->log as $entry) {
            if (str_contains($entry, '[robots.txt BLOCKED]')) $statusSummary['blocked_robots']++;
            if (str_contains($entry, '[META BLOCKED]')) $statusSummary['blocked_meta']++;
            if (preg_match('/\[HTTP ([45][0-9]{2})\]/', $entry)) $statusSummary['http_errors']++;
            if (str_contains($entry, '[REDIRECT]')) $statusSummary['redirects']++;
            if (preg_match('/\((\d+\.\d+)s\)/', $entry, $match) && (float)$match[1] > 3.0) $statusSummary['slow_pages']++;
        }

        $healthReport = ["[HEALTH CHECK]"];
        foreach ($statusSummary as $key => $count) {
            $healthReport[] = strtoupper($key) . ': ' . $count;
        }

        file_put_contents("{$this->logDir}/health_report.txt", implode("\n", $healthReport));
        file_put_contents("{$this->logDir}/crawl_log.txt", implode("\n", $this->log));

        // Send log via email if configured
        if (!empty($this->emailLog) && filter_var($this->emailLog, FILTER_VALIDATE_EMAIL)) {
            $sent = mail($this->emailLog, "Sitemap Crawl Report", implode("\n", $this->log));
            if ($sent) {
                $this->log[] = "[MAIL] Report sent to: {$this->emailLog}";
            } else {
                $this->log[] = "[MAIL ERROR] Failed to send email to: {$this->emailLog}";
            }
        }

        // Notify search engines if enabled
        if ($this->pingSearchEngines) {
            $this->pingSearchEngines();
        }

        // ✅ Final: Export Graph
        if ($this->exportGraph && !empty($this->graphEdges)) {
            $this->exportGraphJson();       // Save crawl structure as JSON
            $this->exportGraphHtml();       // Create interactive HTML map
            $this->log[] = "[GRAPH] Crawl map exported as JSON + HTML.";
        }
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
            $this->log[] = "[ROBOTS] robots.txt not found or unreadable at $robotsUrl.";
            return;
        }

        if ($this->debug) {
            $this->log[] = "[ROBOTS] Successfully fetched robots.txt from $robotsUrl";
        }

        // Extract crawl-delay
        if (preg_match('/crawl-delay:\s*(\d+)/i', $robotsContent, $match)) {
            $this->crawlDelay = (int)$match[1];
            $this->log[] = "[ROBOTS] Crawl-delay detected: {$this->crawlDelay}s";
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
            $this->log[] = "[ROBOTS] Loaded " . count($this->disallowedPaths) . " rules for agent: {$ua}";
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
            $this->log[] = "[ROBOTS CHECK] $path matched {$matchedRule[0]}: {$matchedRule[1]}";
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
     * Creates the XML sitemap from the list of visited URLs.
     * Adds dynamic priority and changefreq if enabled.
     * Optionally compresses the result with GZIP.
     */
    private function createSitemap(): void
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $urlset = $dom->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $dom->appendChild($urlset);

        if (empty($this->visited)) {
            $this->log[] = "[SITEMAP] No URLs to write.";
            return;
        }

        foreach (array_keys($this->visited) as $url) {
            // Log clean URL
            $this->log[] = "[CLEAN URL] $url => " . urlencode($url);

            $priority = $this->getPriorityForUrl($url);
            $freq = $this->getChangefreqForUrl($url);

            // Debug values
            if ($this->debug) {
                $this->log[] = "[PRIORITY DEBUG] $url => $priority";
                $this->log[] = "[FREQ DEBUG] $url => $freq";
            }

            $urlEl = $dom->createElement('url');
            $urlEl->appendChild($dom->createElement('loc', htmlspecialchars($url)));
            $urlEl->appendChild($dom->createElement('lastmod', date('Y-m-d')));
            $urlEl->appendChild($dom->createElement('changefreq', $freq));
            $urlEl->appendChild($dom->createElement('priority', $priority));
            $urlset->appendChild($urlEl);
        }

        if ($this->debug) {
            $this->log[] = "[SITEMAP] Using dynamic priority: " . ($this->usePriorityRules ? 'yes' : 'no');
            $this->log[] = "[SITEMAP] Using dynamic changefreq: " . ($this->useChangefreqRules ? 'yes' : 'no');
        }

        // Save output
        $xmlOutput = $dom->saveXML();
        $outputFile = $this->outputPath;

        if ($this->useGzip) {
            $outputFile .= '.gz';
            if (file_put_contents($outputFile, gzencode($xmlOutput)) !== false) {
                $this->log[] = "[SITEMAP] GZIP sitemap saved to: $outputFile";
            } else {
                $this->log[] = "[ERROR] Failed to write GZIP sitemap: $outputFile";
            }
        } else {
            if (file_put_contents($outputFile, $xmlOutput) !== false) {
                $this->log[] = "[SITEMAP] Sitemap saved to: $outputFile";
            } else {
                $this->log[] = "[ERROR] Failed to write sitemap: $outputFile";
            }
        }
    }


    // Notifies search engines about the updated sitemap via ping URLs.
    /**
     * Notifies major search engines by pinging them with the sitemap URL.
     */
    private function pingSearchEngines(): void
    {
        // Use sitemap_index.xml if splitBySite is enabled
        if ($this->splitBySite) {
            $base = $this->getBaseUrlFromFirstDomain();
            $sitemapUrl = $base . '/sitemap_index.xml';
            $this->log[] = "[PING] Notifying search engines using sitemap index: $sitemapUrl";
        } else {
            $sitemapUrl = $this->startUrl . '/sitemap.xml' . ($this->useGzip ? '.gz' : '');
            $this->log[] = "[PING] Notifying search engines using single sitemap: $sitemapUrl";
        }

        // Define ping endpoints
        $engines = [
            'Google' => 'https://www.google.com/ping?sitemap=' . urlencode($sitemapUrl),
            'Bing'   => 'https://www.bing.com/ping?sitemap=' . urlencode($sitemapUrl),
            'Yandex' => 'https://webmaster.yandex.com/ping?sitemap=' . urlencode($sitemapUrl)
        ];

        // Execute pings with timeout and logging
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
                    $this->log[] = "[MATCH PRIO HIGH] '$pattern' matched $url";
                }
                return '0.8';
            }

            if ($this->debug) {
                $this->log[] = "[CHECK PRIO HIGH] Testing '$pattern' on $url";
            }
        }

        // Check low priority patterns
        foreach ($this->priorityPatterns['low'] ?? [] as $pattern) {
            if (str_contains($url, $pattern)) {
                if ($this->debug) {
                    $this->log[] = "[MATCH PRIO LOW] '$pattern' matched $url";
                }
                return '0.2';
            }

            if ($this->debug) {
                $this->log[] = "[CHECK PRIO LOW] Testing '$pattern' on $url";
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
                    $this->log[] = "[MATCH FREQ DAILY] '$pattern' matched $url";
                }
                return 'daily';
            }

            if ($this->debug) {
                $this->log[] = "[CHECK FREQ DAILY] Testing '$pattern' on $url";
            }
        }

        // Check monthly patterns
        foreach ($this->changefreqPatterns['monthly'] ?? [] as $pattern) {
            if (str_contains($url, $pattern)) {
                if ($this->debug) {
                    $this->log[] = "[MATCH FREQ MONTHLY] '$pattern' matched $url";
                }
                return 'monthly';
            }

            if ($this->debug) {
                $this->log[] = "[CHECK FREQ MONTHLY] Testing '$pattern' on $url";
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
                $this->log[] = "[EXCLUDE EXT] .$ext matched for $url";
            }
            return true;
        }

        // Match against exclude patterns (wildcards or plain substrings)
        foreach ($this->excludePatterns as $pattern) {
            if ($this->wildcardMatch($url, $pattern)) {
                if ($this->debug) {
                    $this->log[] = "[EXCLUDE PATTERN] '$pattern' matched $url";
                }
                return true;
            }
        }

        // If include-only patterns are set, allow only matching URLs
        if (!empty($this->includeOnlyPatterns)) {
            foreach ($this->includeOnlyPatterns as $pattern) {
                if ($this->wildcardMatch($url, $pattern)) {
                    if ($this->debug) {
                        $this->log[] = "[INCLUDE MATCH] '$pattern' matched $url";
                    }
                    return false;
                }
            }

            if ($this->debug) {
                $this->log[] = "[EXCLUDE] No includeOnlyPattern matched $url";
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
     * Generate a sitemap_index.xml that includes all generated sitemaps
     * This helps search engines discover multiple sitemaps from a single entry point
     */
    private function createSitemapIndex(): void
    {
        if (empty($this->visited)) {
            $this->log[] = "[SITEMAP] No URLs to write.";
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
                $this->log[] = "[CLEAN URL] $url => " . urlencode($url);
                $priority = $this->getPriorityForUrl($url);
                $freq = $this->getChangefreqForUrl($url);

                if ($this->debug) {
                    $this->log[] = "[PRIORITY DEBUG] $url => $priority";
                    $this->log[] = "[FREQ DEBUG] $url => $freq";
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
                    $this->log[] = "[SITEMAP] GZIP sitemap saved to: $gzPath";
                } else {
                    $this->log[] = "[ERROR] Failed to write GZIP sitemap: $gzPath";
                }
            } else {
                if (file_put_contents($outputFile, $xmlOutput) !== false) {
                    $this->log[] = "[SITEMAP] Sitemap saved to: $outputFile";
                } else {
                    $this->log[] = "[ERROR] Failed to write sitemap: $outputFile";
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
            $this->log[] = "[SITEMAP] Index sitemap created: $indexFile";
        }

        if ($this->debug) {
            $this->log[] = "[SITEMAP] Using dynamic priority: " . ($this->usePriorityRules ? 'yes' : 'no');
            $this->log[] = "[SITEMAP] Using dynamic changefreq: " . ($this->useChangefreqRules ? 'yes' : 'no');
        }
    }

    private function exportGraphHtml(): void
    {
        $templatePath = __DIR__ . '/config/crawl_map_template_dark.html';

        if (!file_exists($templatePath)) {
            $this->log[] = "[ERROR] Graph template not found: crawl_map_template.html";
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
}

// Define authorized hash key to protect script access
$authorizedHash = 'YOUR_SECRET_KEY';

// Detect CLI or Web context
$isCli = php_sapi_name() === 'cli';

// Gather CLI or GET parameters into $options
$options = $isCli
    ? getopt("", [
        "url:", "depth::", "gzip", "email::", "resume", "key:",
        "ignoremeta", "prettyxml", "respectrobots", "ping",
        "agent::", "debug", "output::", "threads::", "allowfiles::",
        "filters", "priorityrules", "changefreqrules", "resetcache",
        "resetlog", "splitbysite", "graphmap"
    ])
    : [
        'url'              => $_GET['url'] ?? null,
        'depth'            => $_GET['depth'] ?? null,
        'gzip'             => isset($_GET['gzip']),
        'email'            => $_GET['email'] ?? null,
        'resume'           => isset($_GET['resume']),
        'key'              => $_GET['key'] ?? null,
        'ignoremeta'       => isset($_GET['ignoremeta']),
        'prettyxml'        => isset($_GET['prettyxml']),
        'respectrobots'    => isset($_GET['respectrobots']),
        'ping'             => isset($_GET['ping']),
        'debug'            => isset($_GET['debug']),
        'agent'            => $_GET['agent'] ?? null,
        'output'           => $_GET['output'] ?? null,
        'threads'          => $_GET['threads'] ?? null,
        'allowfiles'       => $_GET['allowfiles'] ?? null,
        'filters'          => isset($_GET['filters']),
        'priorityrules'    => isset($_GET['priorityrules']),
        'changefreqrules'  => isset($_GET['changefreqrules']),
        'resetcache'       => isset($_GET['resetcache']),
        'resetlog'         => isset($_GET['resetlog']),
        'splitbysite'      => isset($_GET['splitbysite']),
        'graphmap'         => isset($_GET['graphmap'])
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