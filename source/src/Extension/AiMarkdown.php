<?php
namespace Merserwis\Plugin\System\AiMarkdown\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;

/**
 * Main plugin class providing clean, cached Markdown negotiation with B2B Price Anchoring,
 * YAML Frontmatter, Hybrid FAQ Extraction/Generation, Merchant Authority Injection,
 * Tab/Accordion unrolling, PDF prioritization, and local AI analytics.
 */
final class AiMarkdown extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onAfterRender'      => 'onAfterRender',
        ];
    }

    public function onAfterInitialise(): void
    {
        $app = $this->getApplication();

        // 1. Obsługa zaplecza administratora
        if ($app->isClient('administrator')) {
            // Przekierowanie z bocznego menu "Komponenty" do wtyczki
            if ($app->input->get('option') === 'com_aimarkdown') {
                $db = Factory::getDbo();
                $query = $db->getQuery(true)
                    ->select($db->quoteName('extension_id'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('aimarkdown'));
                $db->setQuery($query);
                $pluginId = (int) $db->loadResult();

                $app->redirect('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $pluginId);
                return;
            }

            // AJAX: Generowanie pliku /llms.txt na żądanie
            if ($app->input->get('aimarkdown_action') === 'generate_llmstxt') {
                if ($app->getIdentity()->authorise('core.edit', 'com_plugins') && \Joomla\CMS\Session\Session::checkToken('request')) {
                    $result = $this->generateLlmsTxtFile();
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($result);
                    $app->close();
                }

                header('Content-Type: application/json; charset=utf-8', true, 403);
                echo json_encode(['success' => false, 'message' => 'Unauthorized or invalid token']);
                $app->close();
            }

            // AJAX: Czyszczenie bazy logów
            if ($app->input->get('aimarkdown_action') === 'clear_logs') {
                if ($app->getIdentity()->authorise('core.edit', 'com_plugins') && \Joomla\CMS\Session\Session::checkToken('request')) {
                    $db = Factory::getDbo();
                    try {
                        $db->setQuery('TRUNCATE TABLE ' . $db->quoteName('#__aimarkdown_logs'))->execute();
                    } catch (\Throwable $e) {
                        $db->setQuery('DELETE FROM ' . $db->quoteName('#__aimarkdown_logs'))->execute();
                    }

                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true]);
                    $app->close();
                }

                header('Content-Type: application/json; charset=utf-8', true, 403);
                echo json_encode(['success' => false, 'message' => 'Unauthorized or invalid token']);
                $app->close();
            }
            return;
        }

        if (!$app->isClient('site')) {
            return;
        }

        // 2. Obsługa bezpośredniego zapytania o /llms.txt z logowaniem analityki
        $rawUri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('/^\/llms\.txt(\?.*)?$/i', $rawUri)) {
            if ((bool) $this->params->get('enable_llmstxt', 0)) {
                $llmsUrl = rtrim(Uri::root(), '/') . '/llms.txt';

                // Rejestracja bota odpytującego /llms.txt
                $this->logAiVisit($llmsUrl, 1);

                $filePath = JPATH_SITE . '/llms.txt';
                if (!is_file($filePath)) {
                    $this->generateLlmsTxtFile();
                }
                $content = is_file($filePath) ? (string) file_get_contents($filePath) : '';

                if (!headers_sent()) {
                    header('Content-Type: text/markdown; charset=utf-8');
                    header('X-Robots-Tag: all');
                    header('Vary: Accept', false);
                }
                echo $content;
                $app->close();
            }
        }

        // 3. Automatyczne generowanie pliku /llms.txt w tle według interwału
        if ((bool) $this->params->get('enable_llmstxt', 0)) {
            $this->checkAutomatedLlmsRegeneration();
        }

        $method     = $app->input->getMethod();
        $isMarkdown = $this->isMarkdownRequested();

        // 4. Standard HTML requests
        if (!$isMarkdown) {
            if ($method === 'HEAD' && (bool) $this->params->get('show_alternate_link', 1)) {
                $this->sendDiscoveryHeaders();
                $app->close();
            }
            return;
        }

        // 5. Markdown requests: Check cache if enabled
        if (!(bool) $this->params->get('enable_cache', 1)) {
            return;
        }

        $canonicalUrl   = $this->getCleanCanonicalUrl();
        $cachedMarkdown = $this->getCache($canonicalUrl);

        if ($cachedMarkdown !== null) {
            $this->logAiVisit($canonicalUrl, 1);
            $this->sendMarkdownHeaders($canonicalUrl, 'HIT', $cachedMarkdown);

            if ($method !== 'HEAD') {
                echo $cachedMarkdown;
            }

            $app->close();
        }
    }

    public function onAfterRender(): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $method        = $app->input->getMethod();
        $canonicalUrl  = $this->getCleanCanonicalUrl();
        $alternateUrl  = $canonicalUrl . '?output=markdown';
        $showAlternate = (bool) $this->params->get('show_alternate_link', 1);

        // 1. Standard HTML response
        if (!$this->isMarkdownRequested()) {
            $app->setHeader('Vary', 'Accept', false);

            if ($showAlternate) {
                $this->sendDiscoveryHeaders();

                $body = $app->getBody();
                if (stripos($body, '</head>') !== false) {
                    $linkTag = '    <link rel="alternate" type="text/markdown" href="' . htmlspecialchars($alternateUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
                    $body = str_replace('</head>', $linkTag . '</head>', $body);
                    $app->setBody($body);
                }
            }
            return;
        }

        $html = trim($app->getBody());
        if (empty($html)) {
            return;
        }

        // 2. Convert generated HTML into clean Markdown with B2B Pricing, FAQ & Frontmatter
        $markdown = $this->convertToMarkdown($html, $canonicalUrl);

        // 3. Save to cache
        if ((bool) $this->params->get('enable_cache', 1)) {
            $this->setCache($canonicalUrl, $markdown);
        }

        // 4. Log Cache MISS
        $this->logAiVisit($canonicalUrl, 0);

        // 5. Send HTTP headers and flush output
        $this->sendMarkdownHeaders($canonicalUrl, 'MISS', $markdown);

        if ($method !== 'HEAD') {
            echo $markdown;
        }

        $app->close();
    }

    private function isMarkdownRequested(): bool
    {
        $app = $this->getApplication();
        $accept = $app->input->server->getString('HTTP_ACCEPT', '');
        $output = $app->input->get('output', '', 'cmd');
        $test   = $app->input->get('markdown', '', 'cmd');

        return (
            stripos($accept, 'text/markdown') !== false ||
            $output === 'markdown' ||
            $test === '1'
        );
    }

    private function getCleanCanonicalUrl(): string
    {
        $currentUri = Uri::getInstance();
        $canonical  = $currentUri->toString(['scheme', 'host', 'port', 'path']);

        return str_replace(["\r", "\n"], '', $canonical);
    }

    /**
     * Send RFC 8288 agent discovery headers for HTML / HEAD responses.
     */
    private function sendDiscoveryHeaders(): void
    {
        $app          = $this->getApplication();
        $canonicalUrl = $this->getCleanCanonicalUrl();
        $rootUri      = Uri::root();
        $alternateUrl = $canonicalUrl . '?output=markdown';

        $linkHeaders = [
            '<' . $alternateUrl . '>; rel="alternate"; type="text/markdown"',
            '<' . $rootUri . 'kontakt>; rel="service-doc"',
            '<' . $rootUri . 'robots.txt>; rel="describedby"'
        ];

        // Ogłoszenie pliku /llms.txt dla botów AI (jeśli funkcja jest włączona w opcjach)
        if ((bool) $this->params->get('enable_llmstxt', 0)) {
            $linkHeaders[] = '<' . $rootUri . 'llms.txt>; rel="service-desc"';
        }

        $linkHeaderValue = implode(', ', $linkHeaders);

        $app->setHeader('Link', $linkHeaderValue, false);
        $app->setHeader('Vary', 'Accept', false);

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Link: ' . $linkHeaderValue, false);
            header('Vary: Accept', false);
        }
    }

    private function sendMarkdownHeaders(string $canonicalUrl, string $cacheStatus, string $markdownContent): void
    {
        $app        = $this->getApplication();
        $cacheTtl   = (int) $this->params->get('cache_time', 86400);
        $tokenCount = (int) ceil(mb_strlen($markdownContent) / 4);

        $app->setHeader('Content-Type', 'text/markdown; charset=utf-8', true);
        $app->setHeader('Vary', 'Accept', false);
        $app->setHeader('X-Markdown-Cache', $cacheStatus, true);
        $app->setHeader('x-markdown-tokens', (string) $tokenCount, true);

        if (!headers_sent()) {
            header('Content-Type: text/markdown; charset=utf-8');
            header('Vary: Accept', false);
            header('Link: <' . $canonicalUrl . '>; rel="canonical"; type="text/html"');
            header('Cache-Control: public, max-age=' . $cacheTtl);
            header('X-Markdown-Cache: ' . $cacheStatus);
            header('x-markdown-tokens: ' . $tokenCount);
        }
    }

    private function logAiVisit(string $url, int $isCacheHit): void
    {
        if (!(bool) $this->params->get('enable_analytics', 1)) {
            return;
        }

        try {
            $app = $this->getApplication();
            $userAgent = $app->input->server->getString('HTTP_USER_AGENT', '');
            $botName = $this->detectAiBot($userAgent);

            $rawIp = $app->input->server->getString('HTTP_CF_CONNECTING_IP', '')
                ?: $app->input->server->getString('HTTP_X_FORWARDED_FOR', '')
                ?: $app->input->server->getString('REMOTE_ADDR', '');

            if (str_contains($rawIp, ',')) {
                $rawIp = trim(explode(',', $rawIp)[0]);
            }

            $maskedIp = preg_replace(['/\.\d+$/', '/:[0-9a-fA-F]+$/'], ['.xxx', ':xxxx'], $rawIp) ?: 'Unknown';

            $db = Factory::getDbo();
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__aimarkdown_logs'))
                ->columns([
                    $db->quoteName('bot_name'),
                    $db->quoteName('url'),
                    $db->quoteName('ip_address'),
                    $db->quoteName('user_agent'),
                    $db->quoteName('is_cache_hit'),
                    $db->quoteName('created_at')
                ])
                ->values(implode(',', [
                    $db->quote($botName),
                    $db->quote($url),
                    $db->quote($maskedIp),
                    $db->quote(substr($userAgent, 0, 500)),
                    $isCacheHit,
                    'NOW()'
                ]));
            $db->setQuery($query);
            $db->execute();

            if (random_int(1, 100) === 1) {
                $days = (int) $this->params->get('log_retention_days', 30);
                $pruneQuery = $db->getQuery(true)
                    ->delete($db->quoteName('#__aimarkdown_logs'))
                    ->where($db->quoteName('created_at') . ' < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)');
                $db->setQuery($pruneQuery);
                $db->execute();
            }
        } catch (\Throwable $e) {
        }
    }

    private function detectAiBot(string $userAgent): string
    {
        $userAgent = trim($userAgent);

        if (empty($userAgent)) {
            return 'Other: Unknown / Direct';
        }

        $bots = [
            'OAI-SearchBot'       => 'SearchGPT (OAI-SearchBot)',
            'ChatGPT-User'        => 'ChatGPT Search',
            'GPTBot'              => 'OpenAI GPTBot',
            'ClaudeBot'           => 'Anthropic ClaudeBot',
            'Claude-Web'          => 'Anthropic Claude Web',
            'Claude-Search'       => 'Anthropic Claude Search',
            'anthropic-ai'        => 'Anthropic AI',
            'PerplexityBot'       => 'Perplexity AI',
            'Google-Extended'     => 'Google Gemini',
            'Applebot-Extended'   => 'Apple Intelligence',
            'Meta-ExternalAgent'  => 'Meta AI',
            'FacebookBot'         => 'Meta FacebookBot',
            'Bytespider'          => 'ByteDance AI',
            'Amazonbot'           => 'Amazon AI',
            'cohere-ai'           => 'Cohere AI',
            'Diffbot'             => 'Diffbot',
            'CCBot'               => 'Common Crawl',
            'Timpibot'            => 'Timpi AI',
            'isitagentready'      => 'Cloudflare Agent Ready Audit',
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
            if (stripos($userAgent, 'Chrome/') !== false) {
                return 'Chrome Browser';
            }
            if (stripos($userAgent, 'Firefox/') !== false) {
                return 'Firefox Browser';
            }
            if (stripos($userAgent, 'Safari/') !== false && stripos($userAgent, 'Chrome/') === false) {
                return 'Safari Browser';
            }
            if (stripos($userAgent, 'OPR/') !== false || stripos($userAgent, 'Opera/') !== false) {
                return 'Opera Browser';
            }
            return 'Browser Client';
        }

        if (preg_match('/^([^\s;()]+)/', $userAgent, $matches)) {
            return mb_substr(trim($matches[1]), 0, 45);
        }

        return mb_substr($userAgent, 0, 45);
    }

    private function getCache(string $canonicalUrl): ?string
    {
        $cacheFile = $this->getCacheFilePath($canonicalUrl);
        $cacheTtl  = (int) $this->params->get('cache_time', 86400);

        if (is_file($cacheFile)) {
            $fileAge = time() - filemtime($cacheFile);
            if ($fileAge < $cacheTtl) {
                $content = file_get_contents($cacheFile);
                return ($content !== false) ? $content : null;
            }
        }

        return null;
    }

    private function setCache(string $canonicalUrl, string $content): void
    {
        $cacheDir = JPATH_CACHE . '/plg_system_aimarkdown';

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $cacheFile = $this->getCacheFilePath($canonicalUrl);
        @file_put_contents($cacheFile, $content, LOCK_EX);
    }

    private function getCacheFilePath(string $canonicalUrl): string
    {
        $configSignature = substr(hash('sha256', (string) $this->params), 0, 8);
        return JPATH_CACHE . '/plg_system_aimarkdown/' . hash('sha256', $canonicalUrl . '_' . $configSignature) . '.md';
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

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($htmlEncoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $rootUri = Uri::root();

        // 1. Extract universal PDF downloads
        $pdfDownloads = [];
        if ((bool) $this->params->get('prioritize_pdfs', 1)) {
            $pdfDownloads = $this->extractPdfDownloads($xpath, $rootUri);
        }

        // 2. Extract product metadata with B2B Price Anchoring & build YAML Frontmatter
        $meta = $this->extractProductMetadata($xpath, $canonicalUrl);
        $yamlFrontmatter = '';
        if ((bool) $this->params->get('enable_frontmatter', 1)) {
            $yamlFrontmatter = $this->buildYamlFrontmatter($meta, array_keys($pdfDownloads));
        }

        // 3. Process FAQ section (Extract custom FAQ or auto-generate fallback)
        $faqMarkdown = '';
        $customFaqFound = false;

        if ((bool) $this->params->get('enable_faq', 1)) {
            $extractedFaq = $this->extractExistingFaq($xpath);

            if (!empty($extractedFaq)) {
                $customFaqFound = true;
                $faqMarkdown = $this->formatFaqToMarkdown($extractedFaq);
            } elseif ($meta['is_product'] && (bool) $this->params->get('auto_generate_faq', 1)) {
                $generatedFaq = $this->generateAutoFaq($meta);
                $faqMarkdown = $this->formatFaqToMarkdown($generatedFaq);
            }
        }

        // 4. Unroll Balbooa Gridbox Tabs & Accordions
        if ((bool) $this->params->get('unroll_tabs_accordions', 1)) {
            $this->unrollGridboxTabs($xpath, $dom);
            $this->unrollGridboxAccordions($xpath, $dom);
        }

        // 5. Default layout wrappers, forms, and scripts to remove
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
            '//*[contains(@class, "modal")]'
        ];

        if ($customFaqFound) {
            $trash[] = '//details';
            $trash[] = '//*[self::h1 or self::h2 or self::h3][contains(translate(text(), "FAQ", "faq"), "faq")]';
        }

        // 6. User-defined custom exclude selectors
        $customSelectors = (string) $this->params->get('custom_exclude_selectors', '');
        if (!empty(trim($customSelectors))) {
            $lines = preg_split('/\r\n|\r|\n/', $customSelectors);
            if ($lines) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $xpathQuery = $this->convertCssToXPath($line);
                    if (!empty($xpathQuery)) {
                        $trash[] = $xpathQuery;
                    }
                }
            }
        }

        // 7. Balbooa Gridbox optional toggles
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

        // Default for show_author is now 1 (Yes)
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
            try {
                $nodes = $xpath->query($query);
                if ($nodes) {
                    $toRemove = [];
                    foreach ($nodes as $n) {
                        $toRemove[] = $n;
                    }
                    foreach ($toRemove as $n) {
                        if ($n->parentNode) {
                            $n->parentNode->removeChild($n);
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $content = $xpath->query('//div[contains(@class, "ba-gridbox-page")]')->item(0);
        if (!$content) {
            $content = $xpath->query('//main')->item(0) ?: $xpath->query('//body')->item(0);
        }

        if (!$content) {
            return '';
        }

        $md = $this->parseNode($content);
        $md = strip_tags($md);
        $md = preg_replace('/[ \t]+$/m', '', $md);
        $md = preg_replace('/\n{3,}/', "\n\n", $md);
        $md = trim($md);

        // 8. Merchant Header Note Injection (Automatically formatted as blockquote)
        if ((bool) $this->params->get('enable_merchant_context', 1)) {
            $headerText = trim((string) $this->params->get('merchant_header_text', ''));
            if (!empty($headerText)) {
                $parsedHeader = $this->replaceDynamicTags($headerText, $meta);
                $formattedHeader = $this->formatAsBlockquote($parsedHeader);
                $md = $formattedHeader . "\n\n" . $md;
            }
        }

        // 9. Append FAQ Section (Extracted or Auto-generated)
        if (!empty($faqMarkdown)) {
            $md .= $faqMarkdown;
        }

        // 10. Append prioritized PDF downloads
        if (!empty($pdfDownloads)) {
            $pdfSection = "\n\n## Downloads & Documentation\n";
            foreach ($pdfDownloads as $pdfUrl => $pdfTitle) {
                $pdfSection .= "* [" . $pdfTitle . "](" . $pdfUrl . ")\n";
            }
            $md .= $pdfSection;
        }

        // 11. Merchant Footer CTA Injection
        if ((bool) $this->params->get('enable_merchant_context', 1)) {
            $footerText = trim((string) $this->params->get('merchant_footer_text', ''));
            if (!empty($footerText)) {
                $parsedFooter = $this->replaceDynamicTags($footerText, $meta);
                $md .= "\n\n" . $parsedFooter;
            }
        }

        // 12. Prepend YAML Frontmatter
        if (!empty($yamlFrontmatter)) {
            $md = $yamlFrontmatter . "\n\n" . $md;
        }

        return trim($md);
    }

    private function extractExistingFaq(\DOMXPath $xpath): array
    {
        $faqs = [];

        $jsonScripts = $xpath->query('//script[@type="application/ld+json"]');
        if ($jsonScripts) {
            foreach ($jsonScripts as $scriptNode) {
                $rawJson = trim($scriptNode->nodeValue ?? '');
                if (empty($rawJson) || stripos($rawJson, 'FAQPage') === false) {
                    continue;
                }

                $data = json_decode($rawJson, true);
                if (!is_array($data)) {
                    continue;
                }

                $items = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];

                foreach ($items as $item) {
                    if (($item['@type'] ?? '') === 'FAQPage' && !empty($item['mainEntity']) && is_array($item['mainEntity'])) {
                        foreach ($item['mainEntity'] as $qa) {
                            $question = trim($qa['name'] ?? '');
                            $answer = '';
                            if (isset($qa['acceptedAnswer']['text'])) {
                                $answer = trim($qa['acceptedAnswer']['text']);
                            } elseif (isset($qa['acceptedAnswer']) && is_string($qa['acceptedAnswer'])) {
                                $answer = trim($qa['acceptedAnswer']);
                            }

                            if (!empty($question) && !empty($answer)) {
                                $faqs[] = [
                                    'q' => strip_tags(html_entity_decode($question, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                                    'a' => strip_tags(html_entity_decode($answer, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                                ];
                            }
                        }
                        if (!empty($faqs)) {
                            return $faqs;
                        }
                    }
                }
            }
        }

        $detailsList = $xpath->query('//details[.//summary]');
        if ($detailsList && $detailsList->length > 0) {
            foreach ($detailsList as $details) {
                $summary = $xpath->query('.//summary', $details)->item(0);
                if (!$summary) {
                    continue;
                }

                $question = trim(preg_replace('/\s+/', ' ', $summary->textContent));

                $clone = $details->cloneNode(true);
                $sumInClone = (new \DOMXPath($clone->ownerDocument))->query('.//summary', $clone)->item(0);
                if ($sumInClone && $sumInClone->parentNode) {
                    $sumInClone->parentNode->removeChild($sumInClone);
                }
                $answer = trim(preg_replace('/\s+/', ' ', $clone->textContent));

                if (!empty($question) && !empty($answer)) {
                    $faqs[] = [
                        'q' => html_entity_decode($question, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'a' => html_entity_decode($answer, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    ];
                }
            }
        }

        return $faqs;
    }

    private function generateAutoFaq(array $meta): array
    {
        $faqs = [];
        $title        = !empty($meta['title']) ? $meta['title'] : 'Produkt';
        $sku          = !empty($meta['sku']) ? $meta['sku'] : '';
        $brand        = !empty($meta['brand']) ? $meta['brand'] : '';
        $category     = !empty($meta['category']) ? $meta['category'] : '';
        $availability = !empty($meta['availability']) ? $meta['availability'] : 'w magazynie';
        $seller       = trim((string) $this->params->get('merchant_name', ''));

        $faqs[] = [
            'q' => 'Czy ' . $title . ' objęty jest oficjalną gwarancją w Polsce?',
            'a' => 'Tak, ' . $title . ' oferowany przez ' . (!empty($seller) ? $seller : 'oficjalnego dystrybutora') . ' pochodzi z autoryzowanego kanału sprzedaży i objęty jest pełną gwarancją producenta oraz wsparciem serwisowym.'
        ];

        $faqs[] = [
            'q' => 'Czy można zamówić ' . $title . ' ze świadectwem wzorcowania?',
            'a' => 'Tak, urządzenie' . (!empty($sku) ? ' (kod SKU: ' . $sku . ')' : '') . ' może zostać dostarczone ze świadectwem wzorcowania (certyfikatem kalibracji) wystawionym przez akredytowane laboratorium pomiarowe.'
        ];

        $faqs[] = [
            'q' => 'Jaki jest czas realizacji zamówienia na ' . $title . '?',
            'a' => 'Dla urządzeń o statusie dostępności "' . $availability . '" wysyłka realizowana jest standardowo w ciągu 24–48 godzin roboczych bezpośrednio z magazynu centralnego.'
        ];

        if (!empty($category) || !empty($brand)) {
            $extra = !empty($brand) ? 'marki ' . $brand : 'z kategorii ' . $category;
            $faqs[] = [
                'q' => 'Dla kogo przeznaczone jest urządzenie ' . $title . '?',
                'a' => 'Przyrząd ' . $extra . ' został zaprojektowany z myślą o profesjonalistach, technikach, instalatorach oraz inżynierach wymagających wysokiej dokładności pomiarowej i zgodności z normami bezpieczeństwa.'
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
            $q = trim($faq['q']);
            $a = trim($faq['a']);
            $md .= "\n### " . $q . "\n" . $a . "\n";
        }

        return $md;
    }

    private function replaceDynamicTags(string $template, array $meta): string
    {
        $tags = [
            '{title}'        => $meta['title'] ?? '',
            '{sku}'          => $meta['sku'] ?? '',
            '{price}'        => $meta['price'] ?? '',
            '{price_net}'    => !empty($meta['price_net']) ? ($meta['price_net'] . ' ' . ($meta['currency'] ?? 'PLN')) : '',
            '{price_gross}'  => !empty($meta['price_gross']) ? ($meta['price_gross'] . ' ' . ($meta['currency'] ?? 'PLN')) : '',
            '{currency}'     => $meta['currency'] ?? '',
            '{availability}' => $meta['availability'] ?? '',
            '{brand}'        => $meta['brand'] ?? '',
            '{category}'     => $meta['category'] ?? '',
            '{url}'          => $meta['url'] ?? '',
        ];

        return str_replace(array_keys($tags), array_values($tags), $template);
    }

    private function extractPdfDownloads(\DOMXPath $xpath, string $rootUri): array
    {
        $pdfLinks = [];
        $links = $xpath->query('//a[contains(translate(@href, "PDF", "pdf"), ".pdf")]');

        if ($links) {
            foreach ($links as $link) {
                $href = trim($link->getAttribute('href'));
                if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                    continue;
                }

                if (!preg_match('/\.pdf([?#].*)?$/i', $href)) {
                    continue;
                }

                $absoluteUrl = $this->toAbsoluteUrl($href, $rootUri);

                $title = trim(preg_replace('/\s+/', ' ', $link->textContent));
                if (empty($title)) {
                    $title = trim($link->getAttribute('title') ?: $link->getAttribute('aria-label'));
                }
                if (empty($title)) {
                    $filename = basename(parse_url($href, PHP_URL_PATH) ?? '');
                    $title = !empty($filename) ? urldecode(pathinfo($filename, PATHINFO_FILENAME)) : 'Download File';
                }

                if (stripos($title, 'pdf') === false) {
                    $title .= ' (PDF)';
                }

                if (!isset($pdfLinks[$absoluteUrl])) {
                    $pdfLinks[$absoluteUrl] = $title;
                }
            }
        }

        return $pdfLinks;
    }

    private function toAbsoluteUrl(string $url, string $rootUri): string
    {
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return (Uri::getInstance()->isSsl() ? 'https:' : 'http:') . $url;
        }

        $root = rtrim($rootUri, '/');
        $path = ltrim($url, '/');

        return $root . '/' . $path;
    }

    private function unrollGridboxTabs(\DOMXPath $xpath, \DOMDocument $dom): void
    {
        $tabsContainers = $xpath->query('//*[contains(@class, "ba-item-tabs")]|//*[contains(@class, "ba-tabs-wrapper")]');
        if (!$tabsContainers) {
            return;
        }

        foreach ($tabsContainers as $container) {
            $navLinks = $xpath->query('.//ul[contains(@class, "nav-tabs")]//a|.//ul[contains(@class, "ba-tabs-wrapper")]//a', $container);
            $titlesById = [];
            $titlesByIndex = [];
            $idx = 0;

            if ($navLinks) {
                foreach ($navLinks as $link) {
                    $href  = trim($link->getAttribute('href'));
                    $title = trim(preg_replace('/\s+/', ' ', $link->textContent));

                    if (!empty($title)) {
                        if (!empty($href) && str_starts_with($href, '#')) {
                            $targetId = ltrim($href, '#');
                            $titlesById[$targetId] = $title;
                        }
                        $titlesByIndex[$idx] = $title;
                        $idx++;
                    }
                }
            }

            $panes = $xpath->query('.//*[contains(@class, "tab-pane")]|.*//*[contains(@class, "ba-tab-pane")]', $container);
            if ($panes) {
                $paneIdx = 0;
                foreach ($panes as $pane) {
                    $paneId    = $pane->getAttribute('id');
                    $paneTitle = $titlesById[$paneId] ?? ($titlesByIndex[$paneIdx] ?? '');

                    if (!empty($paneTitle)) {
                        $h3 = $dom->createElement('h3', htmlspecialchars($paneTitle, ENT_QUOTES, 'UTF-8'));
                        if ($pane->firstChild) {
                            $pane->insertBefore($h3, $pane->firstChild);
                        } else {
                            $pane->appendChild($h3);
                        }
                    }
                    $paneIdx++;
                }
            }

            $navBars = $xpath->query('.//ul[contains(@class, "nav-tabs")]', $container);
            if ($navBars) {
                foreach ($navBars as $nav) {
                    if ($nav->parentNode) {
                        $nav->parentNode->removeChild($nav);
                    }
                }
            }
        }
    }

    private function unrollGridboxAccordions(\DOMXPath $xpath, \DOMDocument $dom): void
    {
        $accordions = $xpath->query('//*[contains(@class, "ba-item-accordion")]|//*[contains(@class, "ba-accordion-wrapper")]');
        if (!$accordions) {
            return;
        }

        foreach ($accordions as $accordion) {
            $items = $xpath->query('.//*[contains(@class, "accordion-group")]|.*//*[contains(@class, "ba-accordion-item")]|.*//*[contains(@class, "ba-accordion-panel")]', $accordion);

            if ($items && $items->length > 0) {
                foreach ($items as $item) {
                    $titleNode = $xpath->query('.//*[contains(@class, "ba-accordion-title")]|.*//*[contains(@class, "accordion-title")]|.*//*[contains(@class, "accordion-toggle")]|.*//*[contains(@class, "accordion-heading")]', $item)->item(0);
                    $titleText = '';
                    if ($titleNode) {
                        $titleText = trim(preg_replace('/\s+/', ' ', $titleNode->textContent));
                    }

                    $bodyNode = $xpath->query('.//*[contains(@class, "accordion-body")]|.*//*[contains(@class, "ba-accordion-body")]|.*//*[contains(@class, "accordion-inner")]', $item)->item(0);

                    if (!empty($titleText)) {
                        $h3 = $dom->createElement('h3', htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8'));
                        if ($bodyNode && $bodyNode->firstChild) {
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
                        if ($headingWrapper->parentNode) {
                            $headingWrapper->parentNode->removeChild($headingWrapper);
                        }
                    }
                }
            }
        }
    }

    private function convertCssToXPath(string $selector): string
    {
        $selector = trim($selector);
        if (empty($selector)) {
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
        $jsonScripts = $xpath->query('//script[@type="application/ld+json"]');
        if ($jsonScripts) {
            foreach ($jsonScripts as $scriptNode) {
                $rawJson = trim($scriptNode->nodeValue ?? '');
                if (empty($rawJson)) {
                    continue;
                }

                $data = json_decode($rawJson, true);
                if (!is_array($data)) {
                    continue;
                }

                $items = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];

                foreach ($items as $item) {
                    if (!isset($item['@type'])) {
                        continue;
                    }

                    $type = is_array($item['@type']) ? $item['@type'] : [$item['@type']];
                    if (in_array('Product', $type, true)) {
                        $meta['is_product'] = true;

                        if (!empty($item['name'])) {
                            $meta['title'] = (string) $item['name'];
                        }
                        if (!empty($item['sku'])) {
                            $meta['sku'] = (string) $item['sku'];
                        }
                        if (!empty($item['brand'])) {
                            $meta['brand'] = is_array($item['brand']) ? ($item['brand']['name'] ?? '') : (string) $item['brand'];
                        }
                        if (!empty($item['category'])) {
                            $meta['category'] = (string) $item['category'];
                        }
                        if (!empty($item['offers'])) {
                            $offers = is_array($item['offers']) && isset($item['offers'][0]) ? $item['offers'][0] : $item['offers'];
                            if (is_array($offers)) {
                                if (isset($offers['price'])) {
                                    $meta['price'] = (string) $offers['price'];
                                }
                                if (isset($offers['priceCurrency'])) {
                                    $meta['currency'] = (string) $offers['priceCurrency'];
                                }
                                if (isset($offers['availability'])) {
                                    $avail = (string) $offers['availability'];
                                    $meta['availability'] = str_replace('https://schema.org/', '', $avail);
                                }
                            }
                        }
                        break 2;
                    }
                }
            }
        }

        // 2. DOM Fallbacks
        if (empty($meta['title'])) {
            $titleNode = $xpath->query('//*[contains(@class, "ba-item-product-title")]//h1|//h1')->item(0);
            if ($titleNode) {
                $meta['title'] = trim(preg_replace('/\s+/', ' ', $titleNode->textContent));
            }
        }

        if (empty($meta['price'])) {
            $priceNode = $xpath->query('//*[contains(@class, "ba-item-product-price")]|//*[contains(@class, "ba-product-price")]')->item(0);
            if ($priceNode) {
                $priceText = trim(preg_replace('/\s+/', ' ', $priceNode->textContent));
                if (!empty($priceText)) {
                    $meta['price'] = $priceText;
                    $meta['is_product'] = true;
                }
            }
        }

        if (empty($meta['sku'])) {
            $skuNode = $xpath->query('//*[contains(@class, "ba-item-product-sku")]|//*[contains(@class, "ba-sku-value")]')->item(0);
            if ($skuNode) {
                $skuText = trim(preg_replace('/\s+/', ' ', $skuNode->textContent));
                if (!empty($skuText)) {
                    $meta['sku'] = trim(str_ireplace(['sku:', 'kod:'], '', $skuText));
                    $meta['is_product'] = true;
                }
            }
        }

        if (empty($meta['category'])) {
            $catNode = $xpath->query('//*[contains(@class, "ba-item-tags-and-pd-category")]//*[contains(@class, "category")]|//*[contains(@class, "ba-blog-post-category")]')->item(0);
            if ($catNode) {
                $meta['category'] = trim(preg_replace('/\s+/', ' ', $catNode->textContent));
            }
        }

        if (empty($meta['availability'])) {
            $stockNode = $xpath->query('//*[contains(@class, "ba-item-product-stock")]|//*[contains(@class, "ba-stock-value")]')->item(0);
            if ($stockNode) {
                $stockText = trim(preg_replace('/\s+/', ' ', $stockNode->textContent));
                if (!empty($stockText)) {
                    $meta['availability'] = $stockText;
                    $meta['is_product'] = true;
                }
            }
        }

        // 3. B2B Price Anchoring: Calculate explicit Netto / Brutto & VAT
        if ($meta['is_product'] || !empty($meta['price'])) {
            $this->parseB2bPricing($meta, $xpath);
        }

        return $meta;
    }

    /**
     * Parse and anchor B2B pricing (explicit Netto, Brutto and 23% VAT separation).
     */
    private function parseB2bPricing(array &$meta, \DOMXPath $xpath): void
    {
        $priceText = '';
        $priceNodes = $xpath->query('//*[contains(@class, "ba-item-product-price")]|//*[contains(@class, "ba-product-price")]|//*[contains(@class, "product-price")]');
        if ($priceNodes && $priceNodes->length > 0) {
            foreach ($priceNodes as $node) {
                $priceText .= ' ' . $node->textContent;
            }
        }

        $currency = !empty($meta['currency']) ? $meta['currency'] : 'PLN';
        $priceNet = null;
        $priceGross = null;

        // Try extracting explicit Netto from text
        if (preg_match('/(?:cena\s+netto|netto)[\s:]*([0-9\s]+(?:[,\.][0-9]{2})?)/i', $priceText, $mNet)) {
            $priceNet = (float) str_replace([' ', ','], ['', '.'], $mNet[1]);
        } elseif (preg_match('/([0-9\s]+(?:[,\.][0-9]{2})?)\s*(?:zł|pln)?\s*netto/i', $priceText, $mNet)) {
            $priceNet = (float) str_replace([' ', ','], ['', '.'], $mNet[1]);
        }

        // Try extracting explicit Brutto from text
        if (preg_match('/(?:cena\s+brutto|brutto)[\s:]*([0-9\s]+(?:[,\.][0-9]{2})?)/i', $priceText, $mGross)) {
            $priceGross = (float) str_replace([' ', ','], ['', '.'], $mGross[1]);
        } elseif (preg_match('/([0-9\s]+(?:[,\.][0-9]{2})?)\s*(?:zł|pln)?\s*brutto/i', $priceText, $mGross)) {
            $priceGross = (float) str_replace([' ', ','], ['', '.'], $mGross[1]);
        }

        // Fallback calculation using 23% standard Polish VAT
        if ($priceNet === null && !empty($meta['price'])) {
            $rawPrice = (float) str_replace([' ', ','], ['', '.'], preg_replace('/[^0-9,\.]/', '', $meta['price']));
            if ($rawPrice > 0) {
                if (stripos($priceText, 'brutto') !== false && stripos($priceText, 'netto') === false) {
                    $priceGross = $rawPrice;
                    $priceNet   = round($priceGross / 1.23, 2);
                } else {
                    $priceNet   = $rawPrice;
                    $priceGross = round($priceNet * 1.23, 2);
                }
            }
        } elseif ($priceNet !== null && $priceGross === null) {
            $priceGross = round($priceNet * 1.23, 2);
        } elseif ($priceGross !== null && $priceNet === null) {
            $priceNet = round($priceGross / 1.23, 2);
        }

        if ($priceNet !== null && $priceGross !== null) {
            $meta['price_net']   = number_format($priceNet, 2, '.', '');
            $meta['price_gross'] = number_format($priceGross, 2, '.', '');
            $meta['vat_rate']    = '23%';
            $meta['currency']    = $currency;

            $netFormatted   = number_format($priceNet, 2, ',', ' ');
            $grossFormatted = number_format($priceGross, 2, ',', ' ');
            $meta['price']  = "{$netFormatted} {$currency} netto ({$grossFormatted} {$currency} brutto, 23% VAT)";
        }
    }

    private function buildYamlFrontmatter(array $meta, array $pdfUrls = []): string
    {
        if (empty($meta['title']) && !$meta['is_product']) {
            return '';
        }

        $yaml = "---\n";
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

            if (!empty($sellerName)) {
                $orderedKeys['seller'] = $sellerName;
            }
            if (!empty($sellerType)) {
                $orderedKeys['seller_type'] = $sellerType;
            }
        }

        foreach ($orderedKeys as $key => $val) {
            if (!empty($val)) {
                $cleanVal = trim(preg_replace('/\s+/', ' ', (string) $val));
                $cleanVal = str_replace(['\\', '"'], ['\\\\', '\"'], $cleanVal);
                $yaml .= $key . ': "' . $cleanVal . "\"\n";
            }
        }

        if (!empty($pdfUrls)) {
            $yaml .= "downloads:\n";
            foreach ($pdfUrls as $downloadUrl) {
                $cleanUrl = str_replace(['\\', '"'], ['\\\\', '\"'], $downloadUrl);
                $yaml .= '  - "' . $cleanUrl . "\"\n";
            }
        }

        $yaml .= "---";

        return $yaml;
    }

    private function parseNode(\DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return preg_replace('/\s+/', ' ', $node->nodeValue);
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        if ($tag === 'table') {
            return "\n\n" . $this->parseTable($node) . "\n\n";
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $this->parseNode($child);
        }
        $inner = trim($inner);

        switch ($tag) {
            case 'h1':
                return "\n\n# " . $inner . "\n\n";
            case 'h2':
                return "\n\n## " . $inner . "\n\n";
            case 'h3':
                return "\n\n### " . $inner . "\n\n";
            case 'h4':
                return "\n\n#### " . $inner . "\n\n";
            case 'h5':
            case 'h6':
                return "\n\n##### " . $inner . "\n\n";
            case 'p':
                return $inner !== '' ? "\n\n" . $inner . "\n\n" : '';
            case 'strong':
            case 'b':
                return $inner !== '' ? " **" . $inner . "** " : '';
            case 'em':
            case 'i':
                return $inner !== '' ? " *" . $inner . "* " : '';
            case 'a':
                $href = $node->getAttribute('href');
                if (!empty($href) && strpos($href, 'javascript:') === false && strpos($href, '#') !== 0 && $inner !== '') {
                    return ' [' . $inner . '](' . $href . ') ';
                }
                return ' ' . $inner . ' ';
            case 'li':
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
            case 'img':
                if (!$this->params->get('show_images', 1)) {
                    return '';
                }
                $alt = $node->getAttribute('alt');
                $src = $node->getAttribute('src');
                return !empty($src) ? "\n![" . $alt . "](" . $src . ")\n" : '';
            default:
                return ' ' . $inner . ' ';
        }
    }

    private function parseTable(\DOMNode $table): string
    {
        $rows = [];
        $maxCols = 0;

        foreach ($table->getElementsByTagName('tr') as $tr) {
            $row = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['th', 'td'])) {
                    $cellText = trim(preg_replace('/\s+/', ' ', $cell->textContent));
                    $cellText = str_replace('|', '\|', $cellText);
                    $row[] = $cellText;
                }
            }
            if (!empty($row)) {
                $maxCols = max($maxCols, count($row));
                $rows[] = $row;
            }
        }

        if (empty($rows)) {
            return '';
        }

        $output = "\n";
        $headerCreated = false;

        foreach ($rows as $row) {
            while (count($row) < $maxCols) {
                $row[] = '';
            }
            $output .= '| ' . implode(' | ', $row) . " |\n";
            if (!$headerCreated) {
                $output .= '| ' . implode(' | ', array_fill(0, $maxCols, '---')) . " |\n";
                $headerCreated = true;
            }
        }

        return $output . "\n";
    }
    /**
     * Check if /llms.txt needs to be regenerated based on configured interval.
     */
    private function checkAutomatedLlmsRegeneration(): void
    {
        $filePath = JPATH_SITE . '/llms.txt';
        $interval = (int) $this->params->get('llms_auto_interval', 86400);

        if (!is_file($filePath) || (time() - filemtime($filePath) > $interval)) {
            $this->generateLlmsTxtFile();
        }
    }

   /**
     * Generate the /llms.txt file in the site root directory according to https://llmstxt.org.
     */
    public function generateLlmsTxtFile(): array
    {
        try {
            $siteTitle   = trim((string) $this->params->get('llms_site_title', 'Merdroid'));
            $siteSummary = trim((string) $this->params->get('llms_site_summary', ''));
            $rootUri     = rtrim(Uri::root(), '/');

            // Parse excluded URLs
            $rawExcludes = (string) $this->params->get('llms_exclude_urls', '');
            $excludeList = [];
            if (!empty(trim($rawExcludes))) {
                foreach (preg_split('/\r\n|\r|\n/', $rawExcludes) as $line) {
                    $clean = trim($line);
                    if ($clean !== '') {
                        $excludeList[] = str_replace($rootUri, '', $clean);
                    }
                }
            }

            $txt = "# " . $siteTitle . "\n\n";

            if (!empty($siteSummary)) {
                $txt .= "> " . str_replace(["\r", "\n"], ' ', $siteSummary) . "\n\n";
            }

            $db = Factory::getDbo();
            $tables = $db->getTableList();
            $addedUrls = [];
            $totalLinksCount = 0;

            // 1. Core Pages (Główne pozycje z menu Joomla)
            $query = $db->getQuery(true)
                ->select(['title', 'link', 'alias'])
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('parent_id') . ' = 1')
                ->where($db->quoteName('menutype') . ' = ' . $db->quote('main'))
                ->order($db->quoteName('lft') . ' ASC');
            $db->setQuery($query);
            $menuItems = $db->loadAssocList() ?: [];

            if (!empty($menuItems)) {
                $sectionContent = '';
                foreach ($menuItems as $item) {
                    $url = $rootUri . '/' . $item['alias'];
                    if ($this->isUrlExcluded($url, $excludeList) || isset($addedUrls[$url])) {
                        continue;
                    }
                    $sectionContent .= "- [" . $item['title'] . "](" . $url . "): Główne informacje i oferta serwisu.\n";
                    $addedUrls[$url] = true;
                    $totalLinksCount++;
                }
                if (!empty($sectionContent)) {
                    $txt .= "## Główne sekcje\n\n" . $sectionContent . "\n";
                }
            }

            // 2. Balbooa Gridbox Categories & Apps (Kategorie projektów, narzędzi, bloga)
            if (in_array($db->replacePrefix('#__gridbox_categories'), $tables, true)) {
                $catColumns = $db->getTableColumns('#__gridbox_categories');
                $selectCat = ['id', 'title'];
                if (isset($catColumns['alias'])) {
                    $selectCat[] = 'alias';
                }

                $query = $db->getQuery(true)
                    ->select($selectCat)
                    ->from($db->quoteName('#__gridbox_categories'));

                if (isset($catColumns['published'])) {
                    $query->where($db->quoteName('published') . ' = 1');
                }

                $query->order('id ASC');
                $db->setQuery($query);
                $categories = $db->loadAssocList() ?: [];

                if (!empty($categories)) {
                    $sectionContent = '';
                    foreach ($categories as $cat) {
                        $catSlug = !empty($cat['alias']) ? $cat['alias'] : \Joomla\CMS\Filter\OutputFilter::stringURLSafe($cat['title']);
                        $url = $rootUri . '/' . $catSlug;

                        if ($this->isUrlExcluded($url, $excludeList) || isset($addedUrls[$url])) {
                            continue;
                        }
                        $sectionContent .= "- [" . $cat['title'] . "](" . $url . "): Kategoria projektów i materiałów.\n";
                        $addedUrls[$url] = true;
                        $totalLinksCount++;
                    }
                    if (!empty($sectionContent)) {
                        $txt .= "## Kategorie i działy tematyczne\n\n" . $sectionContent . "\n";
                    }
                }
            }

            // 3. Balbooa Gridbox Pages (Wszystkie wpisy: Case Study, Narzędzia, Blog, Produkty, Strony)
            if (in_array($db->replacePrefix('#__gridbox_pages'), $tables, true)) {
                $pageColumns = $db->getTableColumns('#__gridbox_pages');
                
                $selectFields = ['p.id', 'p.title'];
                if (isset($pageColumns['alias'])) {
                    $selectFields[] = 'p.alias';
                }
                if (isset($pageColumns['intro_text'])) {
                    $selectFields[] = 'p.intro_text';
                }
                if (isset($pageColumns['meta_description'])) {
                    $selectFields[] = 'p.meta_description';
                }
                if (isset($pageColumns['app_type'])) {
                    $selectFields[] = 'p.app_type';
                }

                $query = $db->getQuery(true)
                    ->select($selectFields)
                    ->from($db->quoteName('#__gridbox_pages', 'p'));

                if (isset($pageColumns['published'])) {
                    $query->where($db->quoteName('p.published') . ' = 1');
                }

                // Pobieramy do 500 wpisów (zarówno artykułów, case studies, jak i produktów)
                $query->order('p.id DESC')->setLimit(500);
                $db->setQuery($query);
                $pages = $db->loadAssocList() ?: [];

                if (!empty($pages)) {
                    $sectionContent = '';
                    foreach ($pages as $p) {
                        $slug = !empty($p['alias']) ? $p['alias'] : \Joomla\CMS\Filter\OutputFilter::stringURLSafe($p['title']);
                        $url  = $rootUri . '/' . $slug;

                        if ($this->isUrlExcluded($url, $excludeList) || isset($addedUrls[$url])) {
                            continue;
                        }

                        $desc = !empty($p['intro_text']) ? strip_tags($p['intro_text']) : (!empty($p['meta_description']) ? $p['meta_description'] : 'Szczegółowy opis, analiza wdrożenia i specyfikacja.');
                        $desc = trim(preg_replace('/\s+/', ' ', $desc));
                        if (mb_strlen($desc) > 130) {
                            $desc = mb_substr($desc, 0, 127) . '...';
                        }

                        $sectionContent .= "- [" . $p['title'] . "](" . $url . "): " . $desc . "\n";
                        $addedUrls[$url] = true;
                        $totalLinksCount++;
                    }

                    if (!empty($sectionContent)) {
                        $txt .= "## Projekty, wdrożenia i baza wiedzy\n\n" . $sectionContent . "\n";
                    }
                }
            }

            // 4. Standardowe artykuły Joomla (#__content) - jeśli istnieją
            if (in_array($db->replacePrefix('#__content'), $tables, true)) {
                $query = $db->getQuery(true)
                    ->select(['id', 'title', 'alias', 'introtext', 'metadesc'])
                    ->from($db->quoteName('#__content'))
                    ->where($db->quoteName('state') . ' = 1')
                    ->order('id DESC')
                    ->setLimit(100);
                $db->setQuery($query);
                $articles = $db->loadAssocList() ?: [];

                if (!empty($articles)) {
                    $sectionContent = '';
                    foreach ($articles as $art) {
                        $slug = !empty($art['alias']) ? $art['alias'] : \Joomla\CMS\Filter\OutputFilter::stringURLSafe($art['title']);
                        $url  = $rootUri . '/' . $slug;

                        if ($this->isUrlExcluded($url, $excludeList) || isset($addedUrls[$url])) {
                            continue;
                        }

                        $desc = !empty($art['metadesc']) ? $art['metadesc'] : strip_tags($art['introtext']);
                        $desc = trim(preg_replace('/\s+/', ' ', $desc));
                        if (mb_strlen($desc) > 130) {
                            $desc = mb_substr($desc, 0, 127) . '...';
                        }
                        if (empty($desc)) {
                            $desc = 'Informacje i publikacje w serwisie.';
                        }

                        $sectionContent .= "- [" . $art['title'] . "](" . $url . "): " . $desc . "\n";
                        $addedUrls[$url] = true;
                        $totalLinksCount++;
                    }

                    if (!empty($sectionContent)) {
                        $txt .= "## Artykuły i publikacje\n\n" . $sectionContent . "\n";
                    }
                }
            }

            // Zapis do fizycznego pliku JPATH_SITE/llms.txt
            $targetPath = JPATH_SITE . '/llms.txt';
            $success = @file_put_contents($targetPath, $txt, LOCK_EX);

            if ($success === false) {
                return ['success' => false, 'message' => 'Nie można zapisać pliku na dysku. Sprawdź uprawnienia do zapisu w katalogu głównym witryny.'];
            }

            return [
                'success'     => true,
                'message'     => 'Plik /llms.txt został pomyślnie wygenerowany!',
                'date'        => date('Y-m-d H:i:s'),
                'size'        => round(filesize($targetPath) / 1024, 2) . ' KB',
                'links_count' => $totalLinksCount
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Błąd generowania: ' . $e->getMessage()];
        }
    }

    /**
     * Check if a given URL matches any rule in the exclude list.
     */
    private function isUrlExcluded(string $url, array $excludeList): bool
    {
        if (empty($excludeList)) {
            return false;
        }

        foreach ($excludeList as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if (stripos($url, $rule) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Automatically format text as a Markdown blockquote (>), supporting multiline text without double-quoting.
     *
     * @param string $text
     * @return string
     */
    private function formatAsBlockquote(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $text);
        $formatted = [];

        foreach ($lines as $line) {
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