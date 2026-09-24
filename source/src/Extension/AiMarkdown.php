<?php
namespace Merserwis\Plugin\System\AiMarkdown\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

/**
 * Main plugin class providing clean, cached Markdown negotiation with B2B Price Anchoring,
 * YAML Frontmatter, Hybrid FAQ Extraction/Generation, Merchant Authority Injection,
 * Tab/Accordion unrolling, PDF prioritization, and local AI analytics.
 */
final class AiMarkdown extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /** Minimum pause between two failed/attempted background /llms.txt regenerations. */
    private const LLMS_RETRY_SECONDS = 3600;

    /** Query parameters that only switch the output format and must not split the cache. */
    private const FORMAT_PARAMS = ['output', 'markdown'];

    /** Set in onAfterInitialise, executed in onAfterRespond (after the visitor got the page). */
    private bool $llmsRegenerationDue = false;

    /** Parsed JSON-LD objects of the page being converted (parsed once, used by FAQ + metadata). */
    private array $jsonLdItems = [];

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onAfterRender'     => 'onAfterRender',
            'onAfterRespond'    => 'onAfterRespond',
        ];
    }

    public function onAfterInitialise(): void
    {
        $app = $this->getApplication();

        // 1. Obsługa zaplecza administratora
        if ($app->isClient('administrator')) {
            $this->handleAdministratorRequest();
            return;
        }

        if (!$app->isClient('site')) {
            return;
        }

        // 2. Obsługa bezpośredniego zapytania o /llms.txt z logowaniem analityki
        if ($this->isLlmsTxtRequest()) {
            if ((bool) $this->params->get('enable_llmstxt', 0)) {
                $this->serveLlmsTxt();
            }
            return;
        }

        // 3. Automatyczne generowanie /llms.txt: tylko zaznaczamy, praca odbywa się w onAfterRespond
        if ((bool) $this->params->get('enable_llmstxt', 0)) {
            $this->llmsRegenerationDue = $this->isLlmsRegenerationDue();
        }

        // 4. Standard HTML (and HEAD) requests are rendered by Joomla; headers are added in onAfterRender
        if (!$this->isMarkdownRequested()) {
            return;
        }

        // 5. Markdown requests: serve from cache (guests only - see isCacheableRequest())
        if (!(bool) $this->params->get('enable_cache', 1) || !$this->isCacheableRequest()) {
            return;
        }

        $canonicalUrl   = $this->getCleanCanonicalUrl();
        $cachedMarkdown = $this->getCache($this->getCacheKey($canonicalUrl));

        if ($cachedMarkdown !== null) {
            $this->logAiVisit($canonicalUrl, 1);
            $this->emitMarkdown($canonicalUrl, 'HIT', $cachedMarkdown, 200, true);
        }
    }

    public function onAfterRender(): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        // Only HTML documents are touched: JSON/XML/RSS/raw responses (com_ajax, feeds, sitemaps) stay intact
        $document = $app->getDocument();
        if (!$document || $document->getType() !== 'html') {
            return;
        }

        $canonicalUrl = $this->getCleanCanonicalUrl();

        // 1. Standard HTML response
        if (!$this->isMarkdownRequested()) {
            $app->setHeader('Vary', 'Accept', false);

            if ((bool) $this->params->get('show_alternate_link', 1)) {
                $this->addDiscoveryHeaders();

                $body = $app->getBody();
                $pos  = stripos($body, '</head>');
                if ($pos !== false) {
                    $linkTag = '    <link rel="alternate" type="text/markdown" href="'
                        . htmlspecialchars($this->getAlternateUrl(), ENT_QUOTES, 'UTF-8') . '">' . "\n";
                    $app->setBody(substr_replace($body, $linkTag, $pos, 0));
                }
            }
            return;
        }

        $html = trim($app->getBody());
        if ($html === '') {
            return;
        }

        $status    = $this->getResponseStatus();
        $cacheable = $status === 200 && $this->isCacheableRequest();

        // 2. Convert generated HTML into clean Markdown with B2B Pricing, FAQ & Frontmatter
        $markdown = $this->convertToMarkdown($html, $canonicalUrl);

        // 3. Save to cache (only successful guest responses)
        if ($cacheable && (bool) $this->params->get('enable_cache', 1)) {
            $this->setCache($this->getCacheKey($canonicalUrl), $markdown);
        }

        // 4. Log Cache MISS
        $this->logAiVisit($canonicalUrl, 0);

        // 5. Send HTTP headers and flush output
        $this->emitMarkdown($canonicalUrl, 'MISS', $markdown, $status, $cacheable);
    }

    /**
     * Background /llms.txt regeneration, executed after the response was sent to the visitor.
     */
    public function onAfterRespond(): void
    {
        if (!$this->llmsRegenerationDue) {
            return;
        }

        $this->llmsRegenerationDue = false;

        // Release the visitor's connection first (PHP-FPM); skipped in debug mode so the debug console is not cut off
        if (function_exists('fastcgi_finish_request') && !(\defined('JDEBUG') && JDEBUG)) {
            @fastcgi_finish_request();
        }

        $this->generateLlmsTxtFile();
    }

    private function handleAdministratorRequest(): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();

        // Przekierowanie z bocznego menu "Komponenty" do wtyczki
        if ($input->getCmd('option') === 'com_aimarkdown') {
            $app->redirect('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $this->getPluginExtensionId());
            return;
        }

        $action = $input->getCmd('aimarkdown_action', '');
        if ($action !== 'generate_llmstxt' && $action !== 'clear_logs') {
            return;
        }

        // AJAX actions change state: POST + CSRF token + permission are all required
        $user    = $app->getIdentity();
        $allowed = $input->getMethod() === 'POST'
            && $user
            && $user->authorise('core.edit', 'com_plugins')
            && Session::checkToken('request');

        if (!$allowed) {
            $this->sendJson(['success' => false, 'message' => 'Unauthorized or invalid token'], 403);
        }

        $this->sendJson($action === 'generate_llmstxt' ? $this->generateLlmsTxtFile() : $this->clearLogs());
    }

    private function sendJson(array $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->getApplication()->close();
    }

    private function clearLogs(): array
    {
        $db = $this->getDatabase();

        try {
            // TRUNCATE needs the DROP privilege, which many hosts do not grant
            $db->setQuery('TRUNCATE TABLE ' . $db->quoteName('#__aimarkdown_logs'))->execute();
        } catch (\Throwable $e) {
            try {
                $db->setQuery('DELETE FROM ' . $db->quoteName('#__aimarkdown_logs'))->execute();
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => 'Nie można wyczyścić tabeli logów.'];
            }
        }

        return ['success' => true];
    }

    private function getPluginExtensionId(): int
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('aimarkdown'));

        return (int) $db->setQuery($query)->loadResult();
    }

    private function isLlmsTxtRequest(): bool
    {
        $path     = rawurldecode(Uri::getInstance()->getPath());
        $expected = rtrim(Uri::root(true), '/') . '/llms.txt';

        return strcasecmp($path, $expected) === 0;
    }

    private function serveLlmsTxt(): void
    {
        $app = $this->getApplication();

        // Rejestracja bota odpytującego /llms.txt
        $this->logAiVisit(rtrim(Uri::root(), '/') . '/llms.txt', 1);

        $filePath = $this->getLlmsFilePath();
        if (!is_file($filePath)) {
            $this->generateLlmsTxtFile();
        }

        $content = is_file($filePath) ? (string) file_get_contents($filePath) : '';

        if (!headers_sent()) {
            if ($content === '') {
                http_response_code(503);
                header('Retry-After: 3600');
            }
            header('Content-Type: text/markdown; charset=utf-8');
            header('X-Robots-Tag: all');
            header('Cache-Control: public, max-age=3600');
        }

        if ($app->getInput()->getMethod() !== 'HEAD') {
            echo $content;
        }

        $app->close();
    }

    private function isMarkdownRequested(): bool
    {
        $input  = $this->getApplication()->getInput();
        $accept = $input->server->getString('HTTP_ACCEPT', '');
        $output = $input->get('output', '', 'cmd');
        $test   = $input->get('markdown', '', 'cmd');

        return (
            stripos($accept, 'text/markdown') !== false ||
            $output === 'markdown' ||
            $test === '1'
        );
    }

    /**
     * Markdown is cached only for anonymous GET/HEAD requests. A logged-in user's page may contain
     * restricted content that must never be replayed to guests from the shared cache.
     */
    private function isCacheableRequest(): bool
    {
        $app    = $this->getApplication();
        $method = $app->getInput()->getMethod();
        $user   = $app->getIdentity();

        return ($method === 'GET' || $method === 'HEAD') && (!$user || $user->guest);
    }

    private function getCanonicalPath(): string
    {
        return str_replace(["\r", "\n"], '', Uri::getInstance()->toString(['scheme', 'host', 'port', 'path']));
    }

    /**
     * Canonical URL of the current page including its normalised query (pagination, filters and
     * non-SEF URLs such as index.php?option=...&id=5 stay distinct), without the format switches.
     */
    private function getCleanCanonicalUrl(): string
    {
        $query = $this->getNormalizedQuery();

        return $this->getCanonicalPath() . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Query string without the format switches, keys sorted - so ?page=2 and ?output=markdown&page=2
     * share one cache entry, while different pages/filters no longer overwrite each other.
     */
    private function getNormalizedQuery(): string
    {
        $query = Uri::getInstance()->getQuery(true);

        foreach (self::FORMAT_PARAMS as $param) {
            unset($query[$param]);
        }

        if (empty($query)) {
            return '';
        }

        ksort($query);

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function getAlternateUrl(): string
    {
        $query = $this->getNormalizedQuery();

        return $this->getCanonicalPath() . '?' . ($query !== '' ? $query . '&' : '') . 'output=markdown';
    }

    private function getCacheKey(string $canonicalUrl): string
    {
        // The canonical URL already carries the normalised query string
        return $canonicalUrl;
    }

    private function getResponseStatus(): int
    {
        $status = 0;

        foreach ($this->getApplication()->getHeaders() as $header) {
            if (strtolower((string) ($header['name'] ?? '')) === 'status') {
                $status = (int) $header['value'];
            }
        }

        if ($status === 0) {
            $status = (int) http_response_code();
        }

        return $status > 0 ? $status : 200;
    }

    /**
     * Add RFC 8288 agent discovery headers to an HTML response (sent by Joomla together with the page).
     */
    private function addDiscoveryHeaders(): void
    {
        $app     = $this->getApplication();
        $rootUri = Uri::root();

        $linkHeaders = [
            '<' . $this->getAlternateUrl() . '>; rel="alternate"; type="text/markdown"',
        ];

        $serviceDoc = trim((string) $this->params->get('service_doc_path', 'kontakt'), " /\t\n\r");
        if ($serviceDoc !== '') {
            $linkHeaders[] = '<' . $rootUri . $serviceDoc . '>; rel="service-doc"';
        }

        $linkHeaders[] = '<' . $rootUri . 'robots.txt>; rel="describedby"';

        // Ogłoszenie pliku /llms.txt dla botów AI (jeśli funkcja jest włączona w opcjach)
        if ((bool) $this->params->get('enable_llmstxt', 0)) {
            $linkHeaders[] = '<' . $rootUri . 'llms.txt>; rel="service-desc"';
        }

        $app->setHeader('Link', implode(', ', $linkHeaders), false);
    }

    /**
     * Send the Markdown response and terminate. Joomla's own header queue is bypassed on purpose,
     * because the application is closed before respond(), so headers are written directly.
     */
    private function emitMarkdown(string $canonicalUrl, string $cacheStatus, string $markdown, int $status, bool $public): void
    {
        $app        = $this->getApplication();
        $cacheTtl   = (int) $this->params->get('cache_time', 86400);
        $tokenCount = (int) ceil(mb_strlen($markdown) / 4);

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/markdown; charset=utf-8');
            header('Vary: Accept');
            header('Link: <' . $canonicalUrl . '>; rel="canonical"; type="text/html"');
            header('Cache-Control: ' . ($public ? 'public, max-age=' . $cacheTtl : 'private, no-store'));
            header('X-Markdown-Cache: ' . $cacheStatus);
            header('x-markdown-tokens: ' . $tokenCount);
        }

        if ($app->getInput()->getMethod() !== 'HEAD') {
            echo $markdown;
        }

        $app->close();
    }

    private function logAiVisit(string $url, int $isCacheHit): void
    {
        if (!(bool) $this->params->get('enable_analytics', 1)) {
            return;
        }

        try {
            $server    = $this->getApplication()->getInput()->server;
            $userAgent = $server->getString('HTTP_USER_AGENT', '');
            $botName   = $this->detectAiBot($userAgent);

            $db    = $this->getDatabase();
            $now   = Factory::getDate()->toSql();
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__aimarkdown_logs'))
                ->columns([
                    $db->quoteName('bot_name'),
                    $db->quoteName('url'),
                    $db->quoteName('ip_address'),
                    $db->quoteName('user_agent'),
                    $db->quoteName('is_cache_hit'),
                    $db->quoteName('created_at'),
                ])
                ->values(implode(',', [
                    $db->quote(mb_substr($botName, 0, 64)),
                    $db->quote(mb_strcut($url, 0, 2048, 'UTF-8')),
                    $db->quote($this->getMaskedClientIp()),
                    $db->quote(mb_strcut($userAgent, 0, 500, 'UTF-8')),
                    $isCacheHit ? 1 : 0,
                    $db->quote($now),
                ]));
            $db->setQuery($query)->execute();

            if (random_int(1, 100) === 1) {
                $days = (int) $this->params->get('log_retention_days', 30);
                $days = min(max($days, 1), 365);

                $pruneQuery = $db->getQuery(true)
                    ->delete($db->quoteName('#__aimarkdown_logs'))
                    ->where($db->quoteName('created_at') . ' < ' . $db->quote(Factory::getDate('-' . $days . ' days')->toSql()));
                $db->setQuery($pruneQuery)->execute();
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Client IP, anonymised (IPv4: last octet, IPv6: /48 prefix kept).
     * Proxy headers are client-controlled and are only trusted when explicitly enabled.
     */
    private function getMaskedClientIp(): string
    {
        $server = $this->getApplication()->getInput()->server;
        $ip     = $server->getString('REMOTE_ADDR', '');

        if ((bool) $this->params->get('trust_proxy_headers', 0)) {
            $forwarded = $server->getString('HTTP_CF_CONNECTING_IP', '') ?: $server->getString('HTTP_X_FORWARDED_FOR', '');
            if ($forwarded !== '') {
                $ip = trim(explode(',', $forwarded)[0]);
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.xxx', $ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                return inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) . '/48';
            }
        }

        return 'Unknown';
    }

    private function detectAiBot(string $userAgent): string
    {
        $userAgent = trim($userAgent);

        if ($userAgent === '') {
            return 'Other: Unknown / Direct';
        }

        $bots = [
            'OAI-SearchBot'        => 'SearchGPT (OAI-SearchBot)',
            'ChatGPT-User'         => 'ChatGPT Search',
            'GPTBot'               => 'OpenAI GPTBot',
            'ClaudeBot'            => 'Anthropic ClaudeBot',
            'Claude-User'          => 'Anthropic Claude User',
            'Claude-Web'           => 'Anthropic Claude Web',
            'Claude-Search'        => 'Anthropic Claude Search',
            'anthropic-ai'         => 'Anthropic AI',
            'Perplexity-User'      => 'Perplexity User',
            'PerplexityBot'        => 'Perplexity AI',
            'Google-Extended'      => 'Google Gemini',
            'Applebot-Extended'    => 'Apple Intelligence',
            'Meta-ExternalAgent'   => 'Meta AI',
            'Meta-ExternalFetcher' => 'Meta AI Fetcher',
            'FacebookBot'          => 'Meta FacebookBot',
            'MistralAI-User'       => 'Mistral Le Chat',
            'DuckAssistBot'        => 'DuckDuckGo DuckAssist',
            'Bytespider'           => 'ByteDance AI',
            'Amazonbot'            => 'Amazon AI',
            'cohere-ai'            => 'Cohere AI',
            'Diffbot'              => 'Diffbot',
            'CCBot'                => 'Common Crawl',
            'Timpibot'             => 'Timpi AI',
            'isitagentready'       => 'Cloudflare Agent Ready Audit',
        ];

        foreach ($bots as $pattern => $name) {
            if (stripos($userAgent, $pattern) !== false) {
                return $name;
            }
        }

        return 'Other: ' . $this->extractClientName($userAgent);
    }

    private function extractClientName(string $userAgent): string
    {
        if (preg_match('/compatible;\s*([a-zA-Z0-9_\-\.]+)/i', $userAgent, $matches)) {
            return mb_substr(trim($matches[1]), 0, 45);
        }

        if (stripos($userAgent, 'Mozilla/') !== false) {
            if (stripos($userAgent, 'Edg/') !== false) {
                return 'Edge Browser';
            }
            // Opera also sends "Chrome/", so it has to be tested before Chrome
            if (stripos($userAgent, 'OPR/') !== false || stripos($userAgent, 'Opera/') !== false) {
                return 'Opera Browser';
            }
            if (stripos($userAgent, 'Chrome/') !== false) {
                return 'Chrome Browser';
            }
            if (stripos($userAgent, 'Firefox/') !== false) {
                return 'Firefox Browser';
            }
            if (stripos($userAgent, 'Safari/') !== false) {
                return 'Safari Browser';
            }
            return 'Browser Client';
        }

        if (preg_match('/^([^\s;()]+)/', $userAgent, $matches)) {
            return mb_substr(trim($matches[1]), 0, 45);
        }

        return mb_substr($userAgent, 0, 45);
    }

    private function getCacheDir(): string
    {
        // JPATH_CACHE is administrator/cache for both site and administrator (Joomla 4+), so the
        // site-side Markdown cache and the admin-side /llms.txt lock/marker share one directory
        return JPATH_CACHE . '/plg_system_aimarkdown';
    }

    private function getCache(string $cacheKey): ?string
    {
        $cacheFile = $this->getCacheFilePath($cacheKey);
        $cacheTtl  = (int) $this->params->get('cache_time', 86400);

        if (is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < $cacheTtl) {
            $content = @file_get_contents($cacheFile);
            return ($content !== false && $content !== '') ? $content : null;
        }

        return null;
    }

    private function setCache(string $cacheKey, string $content): void
    {
        $cacheDir = $this->getCacheDir();

        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return;
        }

        // Write to a temp file and rename: readers never see a half-written entry
        $cacheFile = $this->getCacheFilePath($cacheKey);
        $tmpFile   = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmpFile, $content) === false || !@rename($tmpFile, $cacheFile)) {
            @unlink($tmpFile);
        }

        if (random_int(1, 200) === 1) {
            $this->purgeExpiredCache();
        }
    }

    /**
     * Remove expired entries - previously every URL/config variant stayed on disk forever.
     */
    private function purgeExpiredCache(): void
    {
        $cacheTtl = (int) $this->params->get('cache_time', 86400);
        $now      = time();

        // No GLOB_BRACE: the constant does not exist on musl-based systems (e.g. Alpine)
        foreach (glob($this->getCacheDir() . '/*') ?: [] as $file) {
            if (preg_match('/\.(md|tmp)$/', $file) && $now - (int) @filemtime($file) > $cacheTtl) {
                @unlink($file);
            }
        }
    }

    private function getCacheFilePath(string $cacheKey): string
    {
        $configSignature = substr(hash('sha256', (string) $this->params), 0, 8);
        return $this->getCacheDir() . '/' . hash('sha256', $cacheKey . '_' . $configSignature) . '.md';
    }

    /**
     * Parse HTML, apply B2B Pricing, extract FAQs, apply Merchant Context, and build Markdown.
     */
    private function convertToMarkdown(string $html, string $canonicalUrl): string
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', mb_detect_encoding($html) ?: 'UTF-8');
        }

        $htmlEncoded = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        $dom            = new \DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $dom->loadHTML($htmlEncoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        $xpath   = new \DOMXPath($dom);
        $rootUri = Uri::root();

        // JSON-LD is parsed once and shared by the FAQ and product metadata extractors
        $this->jsonLdItems = $this->parseJsonLd($xpath);

        // 1. Extract product metadata with B2B Price Anchoring
        $meta = $this->extractProductMetadata($xpath, $canonicalUrl);

        // 2. Process FAQ section (Extract custom FAQ or auto-generate fallback)
        $faqMarkdown    = '';
        $customFaqFound = false;

        if ((bool) $this->params->get('enable_faq', 1)) {
            $extractedFaq = $this->extractExistingFaq($xpath);

            if (!empty($extractedFaq)) {
                $customFaqFound = true;
                $faqMarkdown    = $this->formatFaqToMarkdown($extractedFaq);
            } elseif ($meta['is_product'] && (bool) $this->params->get('auto_generate_faq', 1)) {
                $faqMarkdown = $this->formatFaqToMarkdown($this->generateAutoFaq($meta));
            }
        }

        // 3. Unroll Balbooa Gridbox Tabs & Accordions
        if ((bool) $this->params->get('unroll_tabs_accordions', 1)) {
            $this->unrollGridboxTabs($xpath, $dom);
            $this->unrollGridboxAccordions($xpath, $dom);
        }

        // 4. Default layout wrappers, forms, and scripts to remove
        $trash = [
            '//script',
            '//style',
            '//link',
            '//noscript',
            '//svg',
            '//iframe',
            '//form',
            '//header',
            '//footer',
            '//nav',
            '//*[contains(@class, "ba-header")]',
            '//*[contains(@class, "ba-footer")]',
            '//*[contains(@class, "ba-sticky-header")]',
            '//*[contains(@class, "ba-cart-modal")]',
            '//*[contains(@class, "com-baforms-wrapper")]',
            '//*[contains(@class, "cookie")]',
            '//*[contains(@class, "modal")]',
        ];

        if ($customFaqFound) {
            $trash[] = '//details';
            $trash[] = '//*[self::h1 or self::h2 or self::h3][contains(translate(text(), "FAQ", "faq"), "faq")]';
        }

        // 5. User-defined custom exclude selectors
        $customSelectors = (string) $this->params->get('custom_exclude_selectors', '');
        if (trim($customSelectors) !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $customSelectors) ?: [] as $line) {
                $xpathQuery = $this->convertCssToXPath(trim($line));
                if ($xpathQuery !== '') {
                    $trash[] = $xpathQuery;
                }
            }
        }

        // 6. Balbooa Gridbox optional toggles
        if (!$this->params->get('show_category', 1)) {
            $trash[] = '//*[contains(@class, "ba-item-tags-and-pd-category")]//*[contains(@class, "category")]';
            $trash[] = '//*[contains(@class, "ba-blog-post-category")]';
            $trash[] = '//*[contains(@class, "ba-item-category")]';
            $trash[] = '//*[contains(@class, "ba-item-breadcrumb")]';
        }

        if (!$this->params->get('show_tags', 0)) {
            $trash[] = '//*[contains(@class, "ba-item-tags")]';
            $trash[] = '//*[contains(@class, "ba-blog-post-tags")]';
        }

        if (!$this->params->get('show_author', 1)) {
            $trash[] = '//*[contains(@class, "ba-item-post-author")]';
            $trash[] = '//*[contains(@class, "ba-blog-post-author")]';
            $trash[] = '//*[contains(@class, "ba-author")]';
        }

        if (!$this->params->get('show_date', 1)) {
            $trash[] = '//*[contains(@class, "ba-item-post-date")]';
            $trash[] = '//*[contains(@class, "ba-blog-post-date")]';
            $trash[] = '//time';
        }

        if (!$this->params->get('show_description', 1)) {
            $trash[] = '//*[contains(@class, "ba-item-product-description")]';
            $trash[] = '//*[contains(@class, "ba-item-intro-text")]';
        }

        if (!$this->params->get('show_custom_fields', 1)) {
            $trash[] = '//*[contains(@class, "ba-item-product-fields")]';
            $trash[] = '//*[contains(@class, "ba-custom-fields")]';
        }

        foreach ($trash as $query) {
            // An invalid (user-supplied) XPath returns false with a warning instead of throwing
            $nodes = @$xpath->query($query);
            if (!$nodes instanceof \DOMNodeList) {
                continue;
            }

            $toRemove = iterator_to_array($nodes);
            foreach ($toRemove as $n) {
                if ($n->parentNode) {
                    $n->parentNode->removeChild($n);
                }
            }
        }

        $content = $xpath->query('//div[contains(@class, "ba-gridbox-page")]')->item(0)
            ?: $xpath->query('//main')->item(0)
            ?: $xpath->query('//body')->item(0);

        if (!$content) {
            return '';
        }

        // 7. PDF downloads are taken from the cleaned content only (not from header/footer/menus)
        $pdfDownloads = [];
        if ((bool) $this->params->get('prioritize_pdfs', 1)) {
            $pdfDownloads = $this->extractPdfDownloads($xpath, $rootUri, $content);
        }

        // Converter output is plain text + Markdown; no strip_tags() here, it used to delete text like "<50 V"
        $md = $this->normalizeWhitespace($this->parseNode($content));
        $md = trim($md);

        // 8. Merchant Header Note Injection (Automatically formatted as blockquote)
        if ((bool) $this->params->get('enable_merchant_context', 1)) {
            $headerText = trim((string) $this->params->get('merchant_header_text', ''));
            if ($headerText !== '') {
                $md = $this->formatAsBlockquote($this->replaceDynamicTags($headerText, $meta)) . "\n\n" . $md;
            }
        }

        // 9. Append FAQ Section (Extracted or Auto-generated)
        if ($faqMarkdown !== '') {
            $md .= $faqMarkdown;
        }

        // 10. Append prioritized PDF downloads
        if (!empty($pdfDownloads)) {
            $pdfSection = "\n\n## Downloads & Documentation\n";
            foreach ($pdfDownloads as $pdfUrl => $pdfTitle) {
                $pdfSection .= '* [' . $this->escapeLinkText($pdfTitle) . '](' . $this->escapeLinkUrl($pdfUrl) . ")\n";
            }
            $md .= $pdfSection;
        }

        // 11. Merchant Footer CTA Injection
        if ((bool) $this->params->get('enable_merchant_context', 1)) {
            $footerText = trim((string) $this->params->get('merchant_footer_text', ''));
            if ($footerText !== '') {
                $md .= "\n\n" . $this->replaceDynamicTags($footerText, $meta);
            }
        }

        // 12. Prepend YAML Frontmatter
        if ((bool) $this->params->get('enable_frontmatter', 1)) {
            $yamlFrontmatter = $this->buildYamlFrontmatter($meta, array_keys($pdfDownloads));
            if ($yamlFrontmatter !== '') {
                $md = $yamlFrontmatter . "\n\n" . $md;
            }
        }

        return trim(preg_replace('/\n{3,}/', "\n\n", $md));
    }

    /**
     * Decode every JSON-LD block into a flat list of objects (supports @graph, top-level arrays
     * and nested arrays of objects).
     */
    private function parseJsonLd(\DOMXPath $xpath): array
    {
        $items   = [];
        $scripts = $xpath->query('//script[@type="application/ld+json"]');

        foreach ($scripts ?: [] as $scriptNode) {
            $rawJson = trim($scriptNode->nodeValue ?? '');
            if ($rawJson === '') {
                continue;
            }

            $data = json_decode($rawJson, true);
            if (!is_array($data)) {
                continue;
            }

            $stack = [$data];
            while ($stack) {
                $node = array_pop($stack);
                if (!is_array($node)) {
                    continue;
                }
                if (array_is_list($node)) {
                    foreach ($node as $child) {
                        $stack[] = $child;
                    }
                    continue;
                }
                if (isset($node['@graph']) && is_array($node['@graph'])) {
                    $stack[] = $node['@graph'];
                }
                if (isset($node['@type'])) {
                    $items[] = $node;
                }
            }
        }

        return $items;
    }

    private function jsonLdHasType(array $item, string $type): bool
    {
        $types = is_array($item['@type'] ?? null) ? $item['@type'] : [$item['@type'] ?? ''];

        return in_array($type, $types, true);
    }

    /**
     * Scalar JSON-LD value as string; arrays/objects (e.g. {"@type":"Brand","name":"X"}) resolved via "name".
     */
    private function jsonLdString($value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            if (isset($value['name'])) {
                return $this->jsonLdString($value['name']);
            }
            if (array_is_list($value) && isset($value[0])) {
                return $this->jsonLdString($value[0]);
            }
        }

        return '';
    }

    private function extractExistingFaq(\DOMXPath $xpath): array
    {
        $faqs = [];

        foreach ($this->jsonLdItems as $item) {
            if (!$this->jsonLdHasType($item, 'FAQPage') || empty($item['mainEntity'])) {
                continue;
            }

            $entities = is_array($item['mainEntity']) && array_is_list($item['mainEntity']) ? $item['mainEntity'] : [$item['mainEntity']];

            foreach ($entities as $qa) {
                if (!is_array($qa)) {
                    continue;
                }

                $question = $this->jsonLdString($qa['name'] ?? '');
                $accepted = $qa['acceptedAnswer'] ?? '';
                $answer   = is_array($accepted) ? $this->jsonLdString($accepted['text'] ?? '') : $this->jsonLdString($accepted);

                if ($question !== '' && $answer !== '') {
                    $faqs[] = [
                        'q' => $this->htmlToPlainText($question),
                        'a' => $this->htmlToPlainText($answer),
                    ];
                }
            }

            if (!empty($faqs)) {
                return $faqs;
            }
        }

        foreach ($xpath->query('//details[.//summary]') ?: [] as $details) {
            $question = '';
            $answer   = '';

            foreach ($details->childNodes as $child) {
                if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'summary') {
                    $question .= ' ' . $child->textContent;
                } else {
                    $answer .= ' ' . $child->textContent;
                }
            }

            $question = trim(preg_replace('/\s+/u', ' ', $question));
            $answer   = trim(preg_replace('/\s+/u', ' ', $answer));

            if ($question !== '' && $answer !== '') {
                $faqs[] = ['q' => $question, 'a' => $answer];
            }
        }

        return $faqs;
    }

    private function htmlToPlainText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function generateAutoFaq(array $meta): array
    {
        $faqs         = [];
        $title        = !empty($meta['title']) ? $meta['title'] : 'Produkt';
        $sku          = !empty($meta['sku']) ? $meta['sku'] : '';
        $brand        = !empty($meta['brand']) ? $meta['brand'] : '';
        $category     = !empty($meta['category']) ? $meta['category'] : '';
        $availability = !empty($meta['availability']) ? $meta['availability'] : 'w magazynie';
        $seller       = trim((string) $this->params->get('merchant_name', ''));

        $faqs[] = [
            'q' => 'Czy ' . $title . ' objęty jest oficjalną gwarancją w Polsce?',
            'a' => 'Tak, ' . $title . ' oferowany przez ' . ($seller !== '' ? $seller : 'oficjalnego dystrybutora') . ' pochodzi z autoryzowanego kanału sprzedaży i objęty jest pełną gwarancją producenta oraz wsparciem serwisowym.',
        ];

        $faqs[] = [
            'q' => 'Czy można zamówić ' . $title . ' ze świadectwem wzorcowania?',
            'a' => 'Tak, urządzenie' . ($sku !== '' ? ' (kod SKU: ' . $sku . ')' : '') . ' może zostać dostarczone ze świadectwem wzorcowania (certyfikatem kalibracji) wystawionym przez akredytowane laboratorium pomiarowe.',
        ];

        $faqs[] = [
            'q' => 'Jaki jest czas realizacji zamówienia na ' . $title . '?',
            'a' => 'Dla urządzeń o statusie dostępności "' . $availability . '" wysyłka realizowana jest standardowo w ciągu 24–48 godzin roboczych bezpośrednio z magazynu centralnego.',
        ];

        if ($category !== '' || $brand !== '') {
            $extra  = $brand !== '' ? 'marki ' . $brand : 'z kategorii ' . $category;
            $faqs[] = [
                'q' => 'Dla kogo przeznaczone jest urządzenie ' . $title . '?',
                'a' => 'Przyrząd ' . $extra . ' został zaprojektowany z myślą o profesjonalistach, technikach, instalatorach oraz inżynierach wymagających wysokiej dokładności pomiarowej i zgodności z normami bezpieczeństwa.',
            ];
        }

        return $faqs;
    }

    private function formatFaqToMarkdown(array $faqs): string
    {
        if (empty($faqs)) {
            return '';
        }

        $md = "\n\n## Najczęściej zadawane pytania (FAQ)\n";
        foreach ($faqs as $faq) {
            $md .= "\n### " . trim($faq['q']) . "\n" . trim($faq['a']) . "\n";
        }

        return $md;
    }

    private function replaceDynamicTags(string $template, array $meta): string
    {
        $tags = [
            '{title}'        => $meta['title'] ?? '',
            '{sku}'          => $meta['sku'] ?? '',
            '{price}'        => $meta['price'] ?? '',
            '{price_net}'    => !empty($meta['price_net']) ? ($meta['price_net'] . ' ' . ($meta['currency'] ?: 'PLN')) : '',
            '{price_gross}'  => !empty($meta['price_gross']) ? ($meta['price_gross'] . ' ' . ($meta['currency'] ?: 'PLN')) : '',
            '{currency}'     => $meta['currency'] ?? '',
            '{availability}' => $meta['availability'] ?? '',
            '{brand}'        => $meta['brand'] ?? '',
            '{category}'     => $meta['category'] ?? '',
            '{url}'          => $meta['url'] ?? '',
        ];

        return strtr($template, $tags);
    }

    private function extractPdfDownloads(\DOMXPath $xpath, string $rootUri, \DOMNode $context): array
    {
        $pdfLinks = [];
        $links    = $xpath->query('.//a[contains(translate(@href, "PDF", "pdf"), ".pdf")]', $context);

        foreach ($links ?: [] as $link) {
            $href = trim($link->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || stripos($href, 'javascript:') === 0) {
                continue;
            }

            if (!preg_match('/\.pdf([?#].*)?$/i', $href)) {
                continue;
            }

            $absoluteUrl = $this->toAbsoluteUrl($href, $rootUri);

            $title = trim(preg_replace('/\s+/u', ' ', $link->textContent));
            if ($title === '') {
                $title = trim($link->getAttribute('title') ?: $link->getAttribute('aria-label'));
            }
            if ($title === '') {
                $filename = basename((string) parse_url($href, PHP_URL_PATH));
                $title    = $filename !== '' ? urldecode(pathinfo($filename, PATHINFO_FILENAME)) : 'Download File';
            }

            if (stripos($title, 'pdf') === false) {
                $title .= ' (PDF)';
            }

            if (!isset($pdfLinks[$absoluteUrl])) {
                $pdfLinks[$absoluteUrl] = $title;
            }
        }

        return $pdfLinks;
    }

    private function toAbsoluteUrl(string $url, string $rootUri): string
    {
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url)) {
            // Already absolute (http:, https:, mailto:, tel:, ...)
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return (Uri::getInstance()->isSsl() ? 'https:' : 'http:') . $url;
        }

        if (str_starts_with($url, '/')) {
            // Root-relative path: prefix only scheme+host, the path may already contain the sub-folder
            return Uri::getInstance()->toString(['scheme', 'host', 'port']) . $url;
        }

        return rtrim($rootUri, '/') . '/' . $url;
    }

    private function unrollGridboxTabs(\DOMXPath $xpath, \DOMDocument $dom): void
    {
        $tabsContainers = $xpath->query('//*[contains(@class, "ba-item-tabs")]|//*[contains(@class, "ba-tabs-wrapper")]');

        foreach ($tabsContainers ?: [] as $container) {
            $navLinks      = $xpath->query('.//ul[contains(@class, "nav-tabs")]//a|.//ul[contains(@class, "ba-tabs-wrapper")]//a', $container);
            $titlesById    = [];
            $titlesByIndex = [];

            foreach ($navLinks ?: [] as $link) {
                $href  = trim($link->getAttribute('href'));
                $title = trim(preg_replace('/\s+/u', ' ', $link->textContent));

                if ($title !== '') {
                    if (str_starts_with($href, '#') && strlen($href) > 1) {
                        $titlesById[substr($href, 1)] = $title;
                    }
                    $titlesByIndex[] = $title;
                }
            }

            // Fixed: ".*//" is not a path in XPath 1.0 ("." multiplied by "*"), it broke the whole union query
            $panes   = $xpath->query('.//*[contains(@class, "tab-pane")]|.//*[contains(@class, "ba-tab-pane")]', $container);
            $paneIdx = 0;

            foreach ($panes ?: [] as $pane) {
                $paneTitle = $titlesById[$pane->getAttribute('id')] ?? ($titlesByIndex[$paneIdx] ?? '');

                if ($paneTitle !== '') {
                    $h3 = $dom->createElement('h3');
                    $h3->appendChild($dom->createTextNode($paneTitle));
                    $pane->insertBefore($h3, $pane->firstChild);
                }
                $paneIdx++;
            }

            foreach (iterator_to_array($xpath->query('.//ul[contains(@class, "nav-tabs")]', $container) ?: []) as $nav) {
                if ($nav->parentNode) {
                    $nav->parentNode->removeChild($nav);
                }
            }
        }
    }

    private function unrollGridboxAccordions(\DOMXPath $xpath, \DOMDocument $dom): void
    {
        $accordions = $xpath->query('//*[contains(@class, "ba-item-accordion")]|//*[contains(@class, "ba-accordion-wrapper")]');

        foreach ($accordions ?: [] as $accordion) {
            $items = $xpath->query('.//*[contains(@class, "accordion-group")]|.//*[contains(@class, "ba-accordion-item")]|.//*[contains(@class, "ba-accordion-panel")]', $accordion);

            foreach (iterator_to_array($items ?: []) as $item) {
                $titleNode = $xpath->query('.//*[contains(@class, "ba-accordion-title")]|.//*[contains(@class, "accordion-title")]|.//*[contains(@class, "accordion-toggle")]|.//*[contains(@class, "accordion-heading")]', $item)->item(0);
                $titleText = $titleNode ? trim(preg_replace('/\s+/u', ' ', $titleNode->textContent)) : '';
                $bodyNode  = $xpath->query('.//*[contains(@class, "accordion-body")]|.//*[contains(@class, "ba-accordion-body")]|.//*[contains(@class, "accordion-inner")]', $item)->item(0);

                if ($titleText !== '') {
                    $h3 = $dom->createElement('h3');
                    $h3->appendChild($dom->createTextNode($titleText));
                    if ($bodyNode) {
                        $bodyNode->insertBefore($h3, $bodyNode->firstChild);
                    } else {
                        $item->insertBefore($h3, $item->firstChild);
                    }
                }

                if ($titleNode && $titleNode->parentNode) {
                    $headingWrapper = $titleNode;
                    while ($headingWrapper->parentNode && $headingWrapper->parentNode !== $item && $headingWrapper->parentNode !== $bodyNode) {
                        if ($headingWrapper->parentNode instanceof \DOMElement) {
                            $parentClass = (string) $headingWrapper->parentNode->getAttribute('class');
                            if (stripos($parentClass, 'heading') !== false || stripos($parentClass, 'toggle') !== false) {
                                $headingWrapper = $headingWrapper->parentNode;
                                continue;
                            }
                        }
                        break;
                    }
                    // Never remove a wrapper that contains the (new) body heading
                    if ($headingWrapper->parentNode && !($bodyNode && $this->isAncestorOf($headingWrapper, $bodyNode))) {
                        $headingWrapper->parentNode->removeChild($headingWrapper);
                    }
                }
            }
        }
    }

    private function isAncestorOf(\DOMNode $ancestor, \DOMNode $node): bool
    {
        for ($n = $node; $n !== null; $n = $n->parentNode) {
            if ($n->isSameNode($ancestor)) {
                return true;
            }
        }

        return false;
    }

    private function convertCssToXPath(string $selector): string
    {
        $selector = trim($selector);
        if ($selector === '') {
            return '';
        }

        if (str_starts_with($selector, '/') || str_starts_with($selector, './')) {
            return $selector;
        }

        if (preg_match('/^#([a-zA-Z0-9_-]+)$/', $selector, $matches)) {
            return "//*[@id='{$matches[1]}']";
        }

        if (preg_match('/^\.([a-zA-Z0-9_-]+)$/', $selector, $matches)) {
            return "//*[contains(@class, '{$matches[1]}')]";
        }

        if (preg_match('/^([a-zA-Z0-9_-]+)\.([a-zA-Z0-9_-]+)$/', $selector, $matches)) {
            return "//{$matches[1]}[contains(@class, '{$matches[2]}')]";
        }

        if (preg_match('/^([a-zA-Z0-9_-]+)#([a-zA-Z0-9_-]+)$/', $selector, $matches)) {
            return "//{$matches[1]}[@id='{$matches[2]}']";
        }

        if (preg_match('/^[a-zA-Z0-9_-]+$/', $selector)) {
            return "//{$selector}";
        }

        // Unsupported CSS (combinators, attribute selectors...): fall back to a class-substring match,
        // but never build an XPath from a selector containing quotes
        if (str_contains($selector, "'") || str_contains($selector, '"')) {
            return '';
        }

        return "//*[contains(@class, '{$selector}')]";
    }

    private function extractProductMetadata(\DOMXPath $xpath, string $canonicalUrl): array
    {
        $meta = [
            'url'          => $canonicalUrl,
            'is_product'   => false,
            'title'        => '',
            'sku'          => '',
            'brand'        => '',
            'category'     => '',
            'price'        => '',
            'price_net'    => '',
            'price_gross'  => '',
            'vat_rate'     => '',
            'currency'     => '',
            'availability' => '',
        ];

        // 1. JSON-LD Schema
        foreach ($this->jsonLdItems as $item) {
            if (!$this->jsonLdHasType($item, 'Product')) {
                continue;
            }

            $meta['is_product'] = true;
            $meta['title']      = $this->jsonLdString($item['name'] ?? '');
            $meta['sku']        = $this->jsonLdString($item['sku'] ?? ($item['mpn'] ?? ''));
            $meta['brand']      = $this->jsonLdString($item['brand'] ?? '');
            $meta['category']   = $this->jsonLdString($item['category'] ?? '');

            $offers = $item['offers'] ?? null;
            if (is_array($offers) && array_is_list($offers)) {
                $offers = $offers[0] ?? null;
            }

            if (is_array($offers)) {
                // AggregateOffer carries lowPrice instead of price
                $price = $this->jsonLdString($offers['price'] ?? ($offers['lowPrice'] ?? ''));
                if ($price !== '') {
                    $meta['price'] = $price;
                }
                $meta['currency'] = $this->jsonLdString($offers['priceCurrency'] ?? '');

                $availability = $this->jsonLdString($offers['availability'] ?? '');
                if ($availability !== '') {
                    $meta['availability'] = preg_replace('#^https?://schema\.org/#i', '', $availability);
                }
            }
            break;
        }

        // 2. DOM Fallbacks
        if ($meta['title'] === '') {
            $titleNode = $xpath->query('//*[contains(@class, "ba-item-product-title")]//h1|//h1')->item(0);
            if ($titleNode) {
                $meta['title'] = trim(preg_replace('/\s+/u', ' ', $titleNode->textContent));
            }
        }

        if ($meta['price'] === '') {
            $priceNode = $xpath->query('//*[contains(@class, "ba-item-product-price")]|//*[contains(@class, "ba-product-price")]')->item(0);
            if ($priceNode) {
                $priceText = trim(preg_replace('/\s+/u', ' ', $priceNode->textContent));
                if ($priceText !== '') {
                    $meta['price']      = $priceText;
                    $meta['is_product'] = true;
                }
            }
        }

        if ($meta['sku'] === '') {
            $skuNode = $xpath->query('//*[contains(@class, "ba-item-product-sku")]|//*[contains(@class, "ba-sku-value")]')->item(0);
            if ($skuNode) {
                $skuText = trim(preg_replace('/\s+/u', ' ', $skuNode->textContent));
                if ($skuText !== '') {
                    $meta['sku']        = trim(str_ireplace(['sku:', 'kod:'], '', $skuText));
                    $meta['is_product'] = true;
                }
            }
        }

        if ($meta['category'] === '') {
            $catNode = $xpath->query('//*[contains(@class, "ba-item-tags-and-pd-category")]//*[contains(@class, "category")]|//*[contains(@class, "ba-blog-post-category")]')->item(0);
            if ($catNode) {
                $meta['category'] = trim(preg_replace('/\s+/u', ' ', $catNode->textContent));
            }
        }

        if ($meta['availability'] === '') {
            $stockNode = $xpath->query('//*[contains(@class, "ba-item-product-stock")]|//*[contains(@class, "ba-stock-value")]')->item(0);
            if ($stockNode) {
                $stockText = trim(preg_replace('/\s+/u', ' ', $stockNode->textContent));
                if ($stockText !== '') {
                    $meta['availability'] = $stockText;
                    $meta['is_product']   = true;
                }
            }
        }

        // 3. B2B Price Anchoring: Calculate explicit Netto / Brutto & VAT
        if ($meta['is_product'] || $meta['price'] !== '') {
            $this->parseB2bPricing($meta, $xpath);
        }

        return $meta;
    }

    /**
     * Turn a Polish/international price string into a float.
     * Handles "1 234,56", "1.234,56", "1,234.56", "1234.56" and non-breaking/thin spaces.
     */
    private function parsePriceNumber(string $raw): ?float
    {
        $clean = preg_replace('/[^0-9,.]/', '', $raw);
        if ($clean === '' || !preg_match('/\d/', $clean)) {
            return null;
        }

        $lastComma = strrpos($clean, ',');
        $lastDot   = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // The separator that comes last is the decimal one
            $decimal   = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $clean     = str_replace($thousands, '', $clean);
            $clean     = str_replace($decimal, '.', $clean);
        } elseif ($lastComma !== false) {
            $clean = str_replace(',', '.', $clean);
        } elseif ($lastDot !== false && preg_match('/^\d{1,3}(\.\d{3})+$/', $clean)) {
            // "1.234" or "12.345.678" = thousands grouping, not decimals
            $clean = str_replace('.', '', $clean);
        }

        // More than one dot left means malformed grouping; keep only the last as decimal
        if (substr_count($clean, '.') > 1) {
            $pos   = strrpos($clean, '.');
            $clean = str_replace('.', '', substr($clean, 0, $pos)) . substr($clean, $pos);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * Parse and anchor B2B pricing (explicit Netto, Brutto and VAT separation).
     */
    private function parseB2bPricing(array &$meta, \DOMXPath $xpath): void
    {
        $priceText  = '';
        $priceNodes = $xpath->query('//*[contains(@class, "ba-item-product-price")]|//*[contains(@class, "ba-product-price")]|//*[contains(@class, "product-price")]');
        foreach ($priceNodes ?: [] as $node) {
            $priceText .= ' ' . $node->textContent;
        }

        // Normalise non-breaking/thin spaces (Gridbox formats prices with &nbsp;) before matching
        $priceText = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $priceText);

        $vatPercent = (float) str_replace(',', '.', (string) $this->params->get('vat_rate', '23'));
        if ($vatPercent < 0 || $vatPercent > 100) {
            $vatPercent = 23.0;
        }
        $vatFactor = 1 + $vatPercent / 100;

        $currency   = $meta['currency'] !== '' ? $meta['currency'] : 'PLN';
        $priceNet   = null;
        $priceGross = null;
        $number     = '(\d[\d ]*(?:[.,]\d{3})*(?:[.,]\d{1,2})?)';

        // Try extracting explicit Netto from text
        if (preg_match('/netto[\s:]*' . $number . '/iu', $priceText, $m)
            || preg_match('/' . $number . '\s*(?:zł|pln|eur|€)?\s*netto/iu', $priceText, $m)) {
            $priceNet = $this->parsePriceNumber($m[1]);
        }

        // Try extracting explicit Brutto from text
        if (preg_match('/brutto[\s:]*' . $number . '/iu', $priceText, $m)
            || preg_match('/' . $number . '\s*(?:zł|pln|eur|€)?\s*brutto/iu', $priceText, $m)) {
            $priceGross = $this->parsePriceNumber($m[1]);
        }

        // Zero is not a price (e.g. "Cena netto: na zapytanie" or "0,00 zł")
        $priceNet   = ($priceNet !== null && $priceNet > 0) ? $priceNet : null;
        $priceGross = ($priceGross !== null && $priceGross > 0) ? $priceGross : null;

        // Fallback calculation using the configured VAT rate
        if ($priceNet === null && $priceGross === null && $meta['price'] !== '') {
            $rawPrice = $this->parsePriceNumber($meta['price']);
            if ($rawPrice !== null && $rawPrice > 0) {
                if (stripos($priceText, 'brutto') !== false && stripos($priceText, 'netto') === false) {
                    $priceGross = $rawPrice;
                } else {
                    $priceNet = $rawPrice;
                }
            }
        }

        if ($priceNet !== null && $priceGross === null) {
            $priceGross = round($priceNet * $vatFactor, 2);
        } elseif ($priceGross !== null && $priceNet === null) {
            $priceNet = round($priceGross / $vatFactor, 2);
        }

        if ($priceNet !== null && $priceGross !== null) {
            $vatLabel = rtrim(rtrim(number_format($vatPercent, 2, '.', ''), '0'), '.') . '%';

            $meta['price_net']   = number_format($priceNet, 2, '.', '');
            $meta['price_gross'] = number_format($priceGross, 2, '.', '');
            $meta['vat_rate']    = $vatLabel;
            $meta['currency']    = $currency;

            $netFormatted   = number_format($priceNet, 2, ',', ' ');
            $grossFormatted = number_format($priceGross, 2, ',', ' ');
            $meta['price']  = "{$netFormatted} {$currency} netto ({$grossFormatted} {$currency} brutto, {$vatLabel} VAT)";
        }
    }

    private function buildYamlFrontmatter(array $meta, array $pdfUrls = []): string
    {
        if (empty($meta['title']) && !$meta['is_product']) {
            return '';
        }

        $orderedKeys = [
            'title'        => $meta['title'] ?? '',
            'type'         => $meta['is_product'] ? 'product' : 'article',
            'sku'          => $meta['sku'] ?? '',
            'brand'        => $meta['brand'] ?? '',
            'price'        => $meta['price'] ?? '',
            'price_net'    => $meta['price_net'] ?? '',
            'price_gross'  => $meta['price_gross'] ?? '',
            'vat_rate'     => $meta['vat_rate'] ?? '',
            'currency'     => $meta['currency'] ?? '',
            'availability' => $meta['availability'] ?? '',
            'category'     => $meta['category'] ?? '',
            'url'          => $meta['url'] ?? '',
        ];

        if ((bool) $this->params->get('enable_merchant_context', 1)) {
            $sellerName = trim((string) $this->params->get('merchant_name', ''));
            $sellerType = trim((string) $this->params->get('merchant_type', ''));

            if ($sellerName !== '') {
                $orderedKeys['seller'] = $sellerName;
            }
            if ($sellerType !== '') {
                $orderedKeys['seller_type'] = $sellerType;
            }
        }

        $yaml = "---\n";
        foreach ($orderedKeys as $key => $val) {
            $val = trim((string) $val);
            if ($val !== '') {
                $yaml .= $key . ': ' . $this->yamlQuote($val) . "\n";
            }
        }

        if (!empty($pdfUrls)) {
            $yaml .= "downloads:\n";
            foreach ($pdfUrls as $downloadUrl) {
                $yaml .= '  - ' . $this->yamlQuote((string) $downloadUrl) . "\n";
            }
        }

        return $yaml . '---';
    }

    private function yamlQuote(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        // Strip remaining control characters, which are not allowed unescaped in YAML double-quoted scalars
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $value) . '"';
    }

    private function escapeLinkText(string $text): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\[', '\]'], trim(preg_replace('/\s+/u', ' ', $text)));
    }

    private function escapeLinkUrl(string $url): string
    {
        return str_replace([' ', '(', ')'], ['%20', '%28', '%29'], trim($url));
    }

    private function parseNode(\DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return preg_replace('/\s+/u', ' ', $node->nodeValue);
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        if ($tag === 'table') {
            return "\n\n" . $this->parseTable($node) . "\n\n";
        }

        if ($tag === 'pre') {
            $code = rtrim($node->textContent);
            return $code !== '' ? "\n\n```\n" . str_replace('```', '` ` `', $code) . "\n```\n\n" : '';
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $this->parseNode($child);
        }
        $inner = trim($inner);

        switch ($tag) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
                return $inner !== '' ? "\n\n" . str_repeat('#', (int) $tag[1]) . ' ' . $inner . "\n\n" : '';
            case 'h5':
            case 'h6':
                return $inner !== '' ? "\n\n##### " . $inner . "\n\n" : '';
            case 'p':
                return $inner !== '' ? "\n\n" . $inner . "\n\n" : '';
            case 'strong':
            case 'b':
                return $inner !== '' ? ' **' . $inner . '** ' : '';
            case 'em':
            case 'i':
                return $inner !== '' ? ' *' . $inner . '* ' : '';
            case 'code':
                return $inner !== '' ? ' `' . str_replace('`', "'", $inner) . '` ' : '';
            case 'a':
                $href = trim($node->getAttribute('href'));
                if ($href !== '' && stripos($href, 'javascript:') !== 0 && !str_starts_with($href, '#') && $inner !== '') {
                    return ' [' . $inner . '](' . $this->escapeLinkUrl($this->toAbsoluteUrl($href, Uri::root())) . ') ';
                }
                return ' ' . $inner . ' ';
            case 'li':
                $parent = $node->parentNode;
                if ($parent && strtolower($parent->nodeName) === 'ol') {
                    $position = 1;
                    for ($s = $node->previousSibling; $s !== null; $s = $s->previousSibling) {
                        if ($s instanceof \DOMElement && strtolower($s->nodeName) === 'li') {
                            $position++;
                        }
                    }
                    return "\n" . $position . '. ' . $inner;
                }
                return "\n* " . $inner;
            case 'ul':
            case 'ol':
                return "\n\n" . $inner . "\n\n";
            case 'br':
                return "\n";
            case 'hr':
                return "\n\n---\n\n";
            case 'blockquote':
                return "\n\n> " . str_replace("\n", "\n> ", $inner) . "\n\n";
            case 'div':
            case 'section':
            case 'article':
            case 'main':
            case 'aside':
            case 'figure':
            case 'figcaption':
            case 'address':
            case 'details':
            case 'summary':
            case 'dl':
            case 'dt':
            case 'dd':
            case 'center':
                // Block containers: keep a paragraph break, otherwise headings from nested blocks
                // ended up glued to the preceding text ("...text.  ### Heading")
                return $inner !== '' ? "\n\n" . $inner . "\n\n" : '';
            case 'img':
                if (!$this->params->get('show_images', 1)) {
                    return '';
                }
                $src = trim($node->getAttribute('src') ?: $node->getAttribute('data-src'));
                if ($src === '' || str_starts_with($src, 'data:')) {
                    return '';
                }
                $alt = str_replace(['[', ']'], ['\[', '\]'], trim($node->getAttribute('alt')));
                return "\n![" . $alt . '](' . $this->escapeLinkUrl($this->toAbsoluteUrl($src, Uri::root())) . ")\n";
            default:
                return ' ' . $inner . ' ';
        }
    }

    /**
     * Trim leading/trailing blanks of every line and collapse blank lines, leaving fenced code intact.
     */
    private function normalizeWhitespace(string $md): string
    {
        $parts = preg_split('/(\n```\n.*?\n```\n)/s', $md, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }
            $part      = preg_replace('/^[ \t]+|[ \t]+$/m', '', $part);
            $parts[$i] = preg_replace('/ {2,}/', ' ', $part);
        }

        return preg_replace('/\n{3,}/', "\n\n", implode('', $parts));
    }

    private function parseTable(\DOMNode $table): string
    {
        $rows    = [];
        $maxCols = 0;

        foreach ($table->getElementsByTagName('tr') as $tr) {
            $row = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['th', 'td'], true)) {
                    $cellText = trim(preg_replace('/\s+/u', ' ', $cell->textContent));
                    $row[]    = str_replace('|', '\|', $cellText);
                }
            }
            if (!empty($row)) {
                $maxCols = max($maxCols, count($row));
                $rows[]  = $row;
            }
        }

        if (empty($rows)) {
            return '';
        }

        $output        = "\n";
        $headerCreated = false;

        foreach ($rows as $row) {
            $row     = array_pad($row, $maxCols, '');
            $output .= '| ' . implode(' | ', $row) . " |\n";
            if (!$headerCreated) {
                $output       .= '| ' . implode(' | ', array_fill(0, $maxCols, '---')) . " |\n";
                $headerCreated = true;
            }
        }

        return $output . "\n";
    }

    private function getLlmsFilePath(): string
    {
        return JPATH_ROOT . '/llms.txt';
    }

    /**
     * Check if /llms.txt needs to be regenerated based on configured interval.
     * Attempts are throttled, so an unwritable site root no longer triggers a full
     * rebuild (dozens of DB queries) on every single page view.
     */
    private function isLlmsRegenerationDue(): bool
    {
        $filePath = $this->getLlmsFilePath();
        $interval = max(3600, (int) $this->params->get('llms_auto_interval', 86400));

        if (is_file($filePath) && (time() - (int) filemtime($filePath)) <= $interval) {
            return false;
        }

        $marker = $this->getCacheDir() . '/llms.attempt';

        return !is_file($marker) || (time() - (int) filemtime($marker)) >= self::LLMS_RETRY_SECONDS;
    }

    /**
     * Generate the /llms.txt file in the site root directory according to https://llmstxt.org.
     */
    public function generateLlmsTxtFile(): array
    {
        $cacheDir = $this->getCacheDir();
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        // Only one generator at a time (several visitors may hit the regeneration window together)
        $lock = @fopen($cacheDir . '/llms.lock', 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['success' => false, 'message' => 'Generowanie /llms.txt jest już w toku. Spróbuj ponownie za chwilę.'];
        }

        @touch($cacheDir . '/llms.attempt');

        try {
            return $this->buildLlmsTxtFile();
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Błąd generowania: ' . $e->getMessage()];
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function buildLlmsTxtFile(): array
    {
        $app         = $this->getApplication();
        $siteTitle   = trim((string) $this->params->get('llms_site_title', '')) ?: (string) $app->get('sitename', 'Website');
        $siteSummary = trim((string) $this->params->get('llms_site_summary', ''));
        $rootUri     = rtrim(Uri::root(), '/');
        $publicLevels = array_values(array_unique(array_map('intval', Access::getAuthorisedViewLevels(0)))) ?: [1];

        // Parse excluded URLs
        $excludeList = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $this->params->get('llms_exclude_urls', '')) ?: [] as $line) {
            $clean = trim($line);
            if ($clean !== '') {
                $excludeList[] = str_replace($rootUri, '', $clean);
            }
        }

        $txt = '# ' . $this->escapeLinkText($siteTitle) . "\n\n";

        if ($siteSummary !== '') {
            $txt .= '> ' . trim(preg_replace('/\s+/u', ' ', $siteSummary)) . "\n\n";
        }

        $db              = $this->getDatabase();
        $tables          = $db->getTableList();
        $addedUrls       = [];
        $totalLinksCount = 0;

        $addLink = function (string $title, string $url, string $desc, string &$section) use (&$addedUrls, &$totalLinksCount, $excludeList): void {
            if ($url === '' || isset($addedUrls[$url]) || $this->isUrlExcluded($url, $excludeList)) {
                return;
            }
            $section .= '- [' . $this->escapeLinkText($title) . '](' . $this->escapeLinkUrl($url) . '): ' . $desc . "\n";
            $addedUrls[$url] = true;
            $totalLinksCount++;
        };

        // 1. Core Pages (public top-level site menu items, real routes instead of "/alias")
        $menuType = trim((string) $this->params->get('llms_menutype', 'main'));
        $query    = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'path', 'home', 'language']))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('level') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('access') . ' IN (' . implode(',', $publicLevels) . ')')
            ->order($db->quoteName('lft') . ' ASC');

        if ($menuType !== '') {
            $query->where($db->quoteName('menutype') . ' = ' . $db->quote($menuType));
        }

        $sectionContent = '';
        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $item) {
            $url = $this->siteLink('index.php?Itemid=' . (int) $item['id'])
                ?: ((int) $item['home'] === 1 ? $rootUri . '/' : $rootUri . '/' . $item['path']);
            $addLink($item['title'], $url, 'Oficjalna podstrona: ' . $this->escapeLinkText($item['title']) . ' serwisu ' . $this->escapeLinkText($siteTitle) . '.', $sectionContent);
        }
        if ($sectionContent !== '') {
            $txt .= "## Główne sekcje\n\n" . $sectionContent . "\n";
        }

        $gridboxUrlMode   = (string) $this->params->get('llms_gridbox_url_mode', 'menu');
        $useGridboxRouter = in_array($gridboxUrlMode, ['menu', 'router'], true);
        $gridboxMenu      = $gridboxUrlMode === 'menu'
            ? $this->loadGridboxMenuMap($db, $publicLevels, $rootUri)
            : ['apps' => [], 'pages' => []];

        // 2. Balbooa Gridbox Categories & Apps
        if (in_array($db->replacePrefix('#__gridbox_categories'), $tables, true)) {
            $catColumns = $db->getTableColumns('#__gridbox_categories');
            $selectCat  = ['id', 'title'];
            foreach (['alias', 'app_id', 'description', 'meta_description'] as $column) {
                if (isset($catColumns[$column])) {
                    $selectCat[] = $column;
                }
            }

            $query = $db->getQuery(true)
                ->select($db->quoteName($selectCat))
                ->from($db->quoteName('#__gridbox_categories'))
                ->order($db->quoteName('id') . ' ASC');

            if (isset($catColumns['published'])) {
                $query->where($db->quoteName('published') . ' = 1');
            }
            if (isset($catColumns['access'])) {
                $query->where($db->quoteName('access') . ' IN (' . implode(',', $publicLevels) . ')');
            }

            $sectionContent = '';
            foreach ($db->setQuery($query)->loadAssocList() ?: [] as $cat) {
                $url = '';
                if ($useGridboxRouter && !empty($cat['app_id'])) {
                    $url = $this->gridboxLink(
                        'index.php?option=com_gridbox&view=blog&app=' . (int) $cat['app_id'] . '&id=' . (int) $cat['id'],
                        $gridboxMenu['apps'][(int) $cat['app_id']] ?? ''
                    );
                }
                if ($url === '') {
                    $catSlug = !empty($cat['alias']) ? $cat['alias'] : OutputFilter::stringURLSafe($cat['title']);
                    $url     = $rootUri . '/' . $catSlug;
                }

                $catDesc = $this->htmlToPlainText((string) ($cat['meta_description'] ?? '') ?: (string) ($cat['description'] ?? ''));
                $catDesc = $catDesc !== ''
                    ? $this->truncate($catDesc, 120)
                    : 'Oferta i aparatura w kategorii: ' . $this->escapeLinkText($cat['title']) . '.';

                $addLink($cat['title'], $url, $catDesc, $sectionContent);
            }
            if ($sectionContent !== '') {
                $txt .= "## Kategorie i działy tematyczne\n\n" . $sectionContent . "\n";
            }
        }

        // Pobierz preferencje sortowania i limitu z konfiguracji
        $sortBy   = (string) $this->params->get('llms_sort_by', 'hits');
        $maxItems = max(0, (int) $this->params->get('llms_max_items', 750));

        // 3. Balbooa Gridbox Pages (Sortowane po wyświetleniach / hits)
        if (in_array($db->replacePrefix('#__gridbox_pages'), $tables, true)) {
            $pageColumns  = $db->getTableColumns('#__gridbox_pages');
            $selectFields = ['p.id', 'p.title'];
            foreach (['alias', 'app_id', 'intro_text', 'meta_description'] as $column) {
                if (isset($pageColumns[$column])) {
                    $selectFields[] = 'p.' . $column;
                }
            }

            $hitsColumn = isset($pageColumns['hits']) ? 'hits' : (isset($pageColumns['views']) ? 'views' : '');

            $query = $db->getQuery(true)
                ->select($db->quoteName($selectFields))
                ->from($db->quoteName('#__gridbox_pages', 'p'));

            if (isset($pageColumns['published'])) {
                $query->where($db->quoteName('p.published') . ' = 1');
            }
            if (isset($pageColumns['page_access'])) {
                $query->where($db->quoteName('p.page_access') . ' IN (' . implode(',', $publicLevels) . ')');
            } elseif (isset($pageColumns['access'])) {
                $query->where($db->quoteName('p.access') . ' IN (' . implode(',', $publicLevels) . ')');
            }

            // Sortowanie po wyświetleniach (lub dacie)
            if ($sortBy === 'hits' && $hitsColumn !== '') {
                $query->order($db->quoteName('p.' . $hitsColumn) . ' DESC, ' . $db->quoteName('p.id') . ' DESC');
            } else {
                $query->order($db->quoteName('p.id') . ' DESC');
            }

            // Ustaw limit (0 = bez limitu)
            if ($maxItems > 0) {
                $query->setLimit($maxItems);
            }

            $sectionContent = '';
            foreach ($db->setQuery($query)->loadAssocList() ?: [] as $p) {
                $url = '';
                if (isset($gridboxMenu['pages'][(int) $p['id']])) {
                    // The page has its own menu item: its menu route is the canonical URL.
                    $url = $gridboxMenu['pages'][(int) $p['id']];
                } elseif ($useGridboxRouter) {
                    $url = $this->gridboxLink(
                        'index.php?option=com_gridbox&view=page&id=' . (int) $p['id'],
                        $gridboxMenu['apps'][(int) ($p['app_id'] ?? 0)] ?? ''
                    );
                }
                if ($url === '') {
                    $slug = !empty($p['alias']) ? $p['alias'] : OutputFilter::stringURLSafe($p['title']);
                    $url  = $rootUri . '/' . $slug;
                }

                $desc = $this->htmlToPlainText((string) ($p['intro_text'] ?? '') ?: (string) ($p['meta_description'] ?? ''));
                $desc = $desc !== '' ? $this->truncate($desc, 130) : 'Szczegółowy opis, analiza wdrożenia i specyfikacja.';

                $addLink($p['title'], $url, $desc, $sectionContent);
            }

            if ($sectionContent !== '') {
                $sectionTitle = ($sortBy === 'hits') ? 'Najpopularniejsze produkty i aparatura pomiarowa' : 'Produkty, wdrożenia i baza wiedzy';
                $txt         .= '## ' . $sectionTitle . "\n\n" . $sectionContent . "\n";
            }
        }

        // 4. Standardowe artykuły Joomla (#__content): only public, published and currently live ones
        if (in_array($db->replacePrefix('#__content'), $tables, true)) {
            $now   = $db->quote(Factory::getDate()->toSql());
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'catid', 'language', 'title', 'alias', 'introtext', 'metadesc']))
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('state') . ' = 1')
                ->where($db->quoteName('access') . ' IN (' . implode(',', $publicLevels) . ')')
                ->where('(' . $db->quoteName('publish_up') . ' IS NULL OR ' . $db->quoteName('publish_up') . ' <= ' . $now . ')')
                ->where('(' . $db->quoteName('publish_down') . ' IS NULL OR ' . $db->quoteName('publish_down') . ' > ' . $now . ')')
                ->order($sortBy === 'hits'
                    ? $db->quoteName('hits') . ' DESC, ' . $db->quoteName('id') . ' DESC'
                    : $db->quoteName('id') . ' DESC')
                ->setLimit(150);

            $sectionContent = '';
            foreach ($db->setQuery($query)->loadAssocList() ?: [] as $art) {
                $url = $this->siteLink(RouteHelper::getArticleRoute($art['id'] . ':' . $art['alias'], (int) $art['catid'], $art['language']));
                if ($url === '') {
                    $slug = !empty($art['alias']) ? $art['alias'] : OutputFilter::stringURLSafe($art['title']);
                    $url  = $rootUri . '/' . $slug;
                }

                $desc = $this->htmlToPlainText((string) ($art['metadesc'] ?: $art['introtext']));
                $desc = $desc !== '' ? $this->truncate($desc, 130) : 'Informacje i publikacje w serwisie.';

                $addLink($art['title'], $url, $desc, $sectionContent);
            }

            if ($sectionContent !== '') {
                $txt .= "## Baza wiedzy i artykuły\n\n" . $sectionContent . "\n";
            }
        }

        // Atomic write: a bot fetching /llms.txt never gets a truncated file
        $targetPath = $this->getLlmsFilePath();
        $tmpPath    = $targetPath . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmpPath, $txt) === false || !@rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);
            return ['success' => false, 'message' => 'Nie można zapisać pliku na dysku. Sprawdź uprawnienia do zapisu w katalogu głównym witryny.'];
        }

        clearstatcache(true, $targetPath);

        return [
            'success'     => true,
            'message'     => 'Plik /llms.txt został pomyślnie wygenerowany!',
            'date'        => Factory::getDate('now', $app->get('offset', 'UTC'))->format('Y-m-d H:i:s', true),
            'size'        => round(strlen($txt) / 1024, 2) . ' KB',
            'links_count' => $totalLinksCount,
        ];
    }

    /**
     * Absolute SEF URL of an internal link, built by the site router (works from admin, too).
     */
    private function siteLink(string $internalUrl): string
    {
        try {
            $url = Route::link('site', $internalUrl, false, Route::TLS_IGNORE, true);
        } catch (\Throwable $e) {
            return '';
        }

        return is_string($url) ? $url : '';
    }

    /**
     * SEF URL of a Gridbox page/category. Gridbox builds its own path segments, but when no menu item
     * points at the Gridbox component Joomla prefixes them with "/component/gridbox" and appends
     * "?Itemid=<home>". Gridbox resolves the same path from the site root, so the prefix and Itemid
     * are dropped: /component/gridbox/cat/page?Itemid=101 -> /cat/page.
     *
     * $menuBase is the URL of the menu item that shows the page's Gridbox app (e.g. https://site/oferta).
     * The canonical URL (and the one in Gridbox's sitemap) lives under it: /oferta/cat/page.
     */
    private function gridboxLink(string $internalUrl, string $menuBase = ''): string
    {
        $url = $this->cleanGridboxUrl($this->siteLink($internalUrl));
        if ($url === '' || $menuBase === '') {
            return $url;
        }

        $parts    = parse_url($url);
        $baseParts = parse_url($menuBase);
        if ($parts === false || $baseParts === false) {
            return $url;
        }

        $basePath = rtrim((string) ($baseParts['path'] ?? ''), '/');
        $path     = (string) ($parts['path'] ?? '/');
        if ($basePath === '' || $path === $basePath || str_starts_with($path, $basePath . '/')) {
            return $url;
        }

        return rtrim($menuBase, '/') . $path
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /**
     * Public site menu items pointing at Gridbox: app id => URL of the item showing the whole app
     * (view=blog&app=N without a category) and page id => URL of the item showing that page.
     *
     * @return array{apps: array<int, string>, pages: array<int, string>}
     */
    private function loadGridboxMenuMap(DatabaseInterface $db, array $publicLevels, string $rootUri): array
    {
        $map = ['apps' => [], 'pages' => []];

        try {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'link', 'path', 'home']))
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('link') . ' LIKE ' . $db->quote('index.php?option=com_gridbox&%'))
                ->where($db->quoteName('access') . ' IN (' . implode(',', $publicLevels) . ')')
                ->order($db->quoteName('level') . ' ASC, ' . $db->quoteName('lft') . ' ASC');
            $items = $db->setQuery($query)->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return $map;
        }

        foreach ($items as $item) {
            $vars = [];
            parse_str((string) parse_url((string) $item['link'], PHP_URL_QUERY), $vars);
            $view = (string) ($vars['view'] ?? '');

            if ($view === 'blog' && !empty($vars['app']) && empty($vars['id'])) {
                $key = 'apps';
                $id  = (int) $vars['app'];
            } elseif ($view === 'page' && !empty($vars['id'])) {
                $key = 'pages';
                $id  = (int) $vars['id'];
            } else {
                continue;
            }

            if (isset($map[$key][$id])) {
                continue;
            }

            $map[$key][$id] = $this->siteLink('index.php?Itemid=' . (int) $item['id'])
                ?: ((int) $item['home'] === 1 ? $rootUri . '/' : $rootUri . '/' . $item['path']);
        }

        return $map;
    }

    private function cleanGridboxUrl(string $url): string
    {
        if ($url === '' || !preg_match('#/component/gridbox(?=[/?\#]|$)#i', $url)) {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $path = preg_replace('#/component/gridbox(?=/|$)#i', '', $parts['path'] ?? '', 1);
        $path = ($path === '' || $path === null) ? '/' : $path;

        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            unset($query['Itemid']);
        }

        return (isset($parts['scheme']) ? $parts['scheme'] . '://' : '')
            . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . $path
            . ($query ? '?' . http_build_query($query) : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    private function truncate(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 3) . '...' : $text;
    }

    /**
     * Check if a given URL matches any rule in the exclude list.
     */
    private function isUrlExcluded(string $url, array $excludeList): bool
    {
        foreach ($excludeList as $rule) {
            $rule = trim($rule);
            if ($rule !== '' && stripos($url, $rule) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Automatically format text as a Markdown blockquote (>), supporting multiline text without double-quoting.
     */
    private function formatAsBlockquote(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $formatted = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '') {
                $formatted[] = '>';
            } elseif (str_starts_with($trimmedLine, '>')) {
                $formatted[] = $line;
            } else {
                $formatted[] = '> ' . $line;
            }
        }

        return implode("\n", $formatted);
    }
}
