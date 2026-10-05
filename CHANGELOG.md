# Changelog

All changes of **AI Markdown for Gridbox**, newest first. Each version is also published as a [GitHub release](https://github.com/merserwis/plg_system_aimarkdown/releases) with its installation package.

## 1.6.2 — 2026-10-05

### 💾 Settings file

- A new tab **Settings file** with three buttons:
  - **Export settings** downloads every setting of the form as `ai-markdown-settings-<date>.json` — **including the contents of the text fields** (excluded selectors, `/llms.txt` site title and summary, excluded URLs, merchant name, header and footer texts) and changes not saved yet.
  - **Import settings…** reads such a file and saves the settings at once (after a confirmation), then reloads the page. Only settings the plugin knows are taken; settings missing in the file stay as they are. Files of other extensions are refused.
  - **Restore defaults** brings every setting back to its default after a confirmation.
- Import and reset do what a save of the form does: the Markdown cache is cleared, the site address recorded, a switched-off `/llms.txt` removed. The visit statistics and the `/llms.txt` file are not part of the settings file.
- All three need an administrator who may edit plugins and the security token, like the other buttons of the plugin.

---

**Tested on:** Joomla 6.1.3 (Atum), PHP 8.5: update from 1.6.1; export with Polish texts, HTML and line breaks and an unsaved change; import of an edited file (values, texts, an unknown key ignored); a file of another extension refused; restore defaults (form shows the defaults after the reload); requests without an administrator session and token refused (403).

## 1.6.1 — 2026-10-02

### ❓ Help tooltips

- Every option with a description has a **“?”** beside its name. The explanation appears on hover, on keyboard focus (Tab) and on a click (it then stays open until a click elsewhere or Escape).
- It is shown by CSS next to the “?” itself, so no administrator template can move or hide it. Joomla's *Toggle Inline Help* still works.

### 🌍 Languages

- The administrator now ships **English (default), Polish, Ukrainian and German**. Spanish, French, Arabic and Chinese were removed; sites in those languages show English. Their files left by 1.6.0 are removed on update.

---

**Tested on:** Joomla 6.1.3 (Atum), PHP 8.5: tooltips with a real mouse (hover, click); update from 1.6.0 with files of a removed language present. The generated Markdown and `/llms.txt` are unchanged.

## 1.6.0 — 2026-10-02

The administrator now speaks 8 languages. This release also closes a `/llms.txt` poisoning hole, bounds the cache and the visit log, and makes the Markdown of Gridbox products more complete. **Upgrading is recommended.**

### 🌍 Administrator in 8 languages

- **English, Polish, Ukrainian, German, Spanish, French, Arabic and Chinese (Simplified).** Translated: the plugin settings, the `/llms.txt` panel, the AI Analytics dashboard, the home dashboard widget, the administrator menu entry and all messages.
- The language **follows the Joomla administrator language** automatically. For any other language, and for any text missing in a translation, **English** is used.
- Before 1.6.0 every text was fixed in the code, partly in English and partly in Polish (the dashboard widget and several messages). All of them now come from language files.
- **Right-to-left (Arabic):** addresses, IP numbers and bot names stay readable inside right-to-left tables.
- The generated Markdown and `/llms.txt` are not affected: their content language stays as it was.

### 🔒 Security

- **`/llms.txt` could be poisoned through the `Host` header.** The automatic regeneration ran in a visitor's request and built every link from that request's address. A request with a forged `Host` header at the right moment wrote a `/llms.txt` with links to another domain, served to AI crawlers until the next regeneration. Now a regeneration started by a visitor runs only on the site's own address: `$live_site` of the Global Configuration, or the address recorded when an administrator installs the update, saves the settings or clicks *Generate*.
- **Forged `Host` headers in the Markdown cache.** The cache key no longer contains the host, and the site address is stored in the cache as a placeholder. A forged host neither gets its own entry nor appears in a page other visitors get.
- **The cache could be flooded.** Every query string (`?utm_source=…`, `?x=<random>`) made a new cache file and a full page conversion. Only known parameters (Joomla and Gridbox routing, pagination, search and filters) are now part of the canonical URL and the cache key. At most 20,000 files are kept, the oldest go first.
- **The visit log had no limit.** Requests with forged user agents could grow the table without end. At most 200,000 rows are kept.
- **Proxy IP header:** with *Trust Proxy IP Headers* on, the IP is now the entry of `X-Forwarded-For` added by your own proxy (the rightmost one), not the leftmost one, which the client controls.
- **Error details:** a failed `/llms.txt` generation no longer returns database messages to the browser. The details go to the Joomla log (category `plg_system_aimarkdown`) and are shown only with *Debug System* on.
- **CSRF token in the request body**, not in the address (where it ended up in server logs).
- The **dashboard widget** is shown only to administrators who may manage extensions.
- Markdown responses send `X-Content-Type-Options: nosniff`.

### 🐛 Fixes

- **Cached Markdown outlived changes.** Unpublishing an article, restricting its access or editing a Gridbox page left the old Markdown in the cache for up to the cache lifetime (up to 7 days). Saving or changing the state of content, saving in Gridbox and saving the plugin settings now clear the Markdown cache.
- **Images were Gridbox's lazy-loading placeholder.** With Gridbox lazy loading on, every image came out as `default-lazy-load.webp`. The real address is now taken from `data-gridbox-lazyload-src` / `data-src`.
- **Product photos were missing.** The photos of a Gridbox product slideshow are CSS backgrounds and were skipped. They are now listed as images of the product.
- **A link as the brand.** When a site's structured data gives a link as the brand name (e.g. `index.php?option=com_gridbox&view=page&id=232`), the `brand` field and `{brand}` tag are now left empty instead of showing the link.
- **`/llms.txt` listed trashed, scheduled and expired Gridbox pages.** Gridbox pages are now listed only when not in the trash, already published and not ended — with dates compared in the site time zone, as Gridbox does.
- **"Main sections" of `/llms.txt` was empty on most sites.** The default menu was `main`, which on most sites is the administrator menu. The default is now *all site menus*. Updating from 1.5.x switches a site still on `main` to all site menus when the site has no site menu of that name.
- **Switching `/llms.txt` off or uninstalling left the file online.** The web server serves `/llms.txt` directly, so it stayed public. It is now removed when the generator is switched off and when the plugin is uninstalled.
- **Large stores:** the `/llms.txt` generation is no longer stopped by PHP's time limit or by the visitor closing the connection.
- A failed regular expression on a very large page no longer ends in an empty error page.
- Counts use singular and plural forms ("1 download", "2 downloads").

### ⚙️ Technical notes

- **Updating from 1.5.4 keeps all settings.** The Markdown of the tested pages is identical to 1.5.4. `/llms.txt` additionally gets its "Main sections" block.
- After updating from the command line (CLI), the site address is not known yet: the automatic regeneration of `/llms.txt` starts after the first save of the settings or a click on *Generate*.
- **No PHP warnings** with full error reporting.
- **Tested** on Joomla 6.1.3, PHP 8.5 and Gridbox 2.20.3.1, and against a saved product page of a live Gridbox store (lazy loading, product slideshow).

## 1.5.4 — 2026-09-24

### 🔄 Changed & Improved

* **New Default Gridbox URL Mode: Canonical (menu item + router):**
  * The plugin reads the site menu items that point at Gridbox. A page or category is placed under the menu item that shows its Gridbox app. For example, `https://example.com/mierniki/izolacji-1kv/sonel-mic-10` becomes `https://example.com/oferta/mierniki/izolacji-1kv/sonel-mic-10` for the store app, and blog posts go under `/blog/…`.
  * Both Gridbox menu types are recognised: **App** and **Category** (e.g. a *Gridbox » Category* item with the alias `oferta` for the store). The full category path is placed under the item, e.g. `/oferta/mierniki/mierniki-instalacji-elektrycznych/mierniki-wielofunkcyjne/metrel-mi-3136-eurotestcombo-xc-wielofunkcyjny-miernik-instalacji-elektrycznych`.
  * A Gridbox page that has its own menu item is listed with that item's URL, e.g. `/firma/kontakt`.
  * Pages from apps with no menu item keep the router path from the site root, as in 1.5.3.
  * Options: **Canonical (menu item + router)** *(Default)*, **Joomla Router (SEF)** (the 1.5.3 behaviour) and **Alias (legacy)**.

* **Automatic Migration on Update:**
  * Sites updating from 1.5.3 or older with **Joomla Router** or **Alias** selected are switched to **Canonical** once, during the update. The administrator is asked to regenerate `llms.txt`.
  * A choice made after installing 1.5.4 is respected: reinstalling or updating again never changes it.

---

### 🛠️ Fixed

* **Non-Canonical Product Links:**
  * 1.5.3 links such as `/mierniki/…` opened the right page, but its `rel="canonical"` pointed to `/oferta/mierniki/…`. AI crawlers got a URL that the page itself does not treat as canonical.
  * On a live site, 40 sampled links in the new format all returned `200`, and each page's canonical tag pointed to itself. The links were spread across the `/oferta/`, `/blog/` and `/promocje/` apps.

---

### ⬆️ Upgrade Notes

* Install `aimarkdown-1.5.4.zip` over 1.5.3. Settings are kept, apart from the one-time URL mode switch described above.
* After the update, click **Generate llms.txt** (*LLMS.txt* tab) so the file is rebuilt with the new URLs.

**Package:** `pkg_aimarkdown` 1.5.4 · `plg_system_aimarkdown` 1.5.4 · `com_aimarkdown` 1.5.4 · `mod_aimarkdown_dashboard` 1.5.4

## 1.5.2 — 2026-09-24

Security & reliability release. **Upgrading from 1.5.1 is strongly recommended.** Verified side by side against 1.5.1 on a clean **Joomla 6.1.3 / PHP 8.4 / MySQL 8.0** install (fresh install, upgrade over 1.5.1, uninstall).

---

### 🚀 Added

* **Trust Proxy IP Headers (`trust_proxy_headers`):**
  * Added an *AI Analytics* setting that controls whether the client IP is read from `CF-Connecting-IP` / `X-Forwarded-For`.
  * Default: **No** (uses `REMOTE_ADDR`). Enable it only behind Cloudflare or a trusted reverse proxy; the header value is validated as an IP address.

* **Gridbox URL Mode (`llms_gridbox_url_mode`):**
  * Added an *LLMS.txt* setting that selects how Gridbox page URLs are built in `/llms.txt`.
  * Options: **Alias** *(Default)* (the 1.5.1 behaviour, `/{alias}`) and **Joomla Router** (SEF URLs from the Joomla/Gridbox router).

* **Menu for "Main sections" (`llms_menutype`):**
  * Added an *LLMS.txt* setting that selects the menu used for the main-sections block (previously hard-coded).
  * Default: `main`.

* **Service Documentation Path (`service_doc_path`):**
  * Added a *Settings* option for the path announced as `rel="service-doc"` (previously hard-coded).
  * Default: `kontakt`. Leave empty to omit the link.

* **VAT Rate (`vat_rate`):**
  * Added a *Merchant & CTA* setting for the VAT rate used in net ⇄ gross price derivation (previously hard-coded).
  * Default: `23` %.

* **AI user agents:**
  * Added detection of `Claude-User`, `Perplexity-User`, `Meta-ExternalFetcher`, `MistralAI-User` and `DuckAssistBot`.

---

### 🔒 Security

* **Restricted Content Leaked Through the Markdown Cache:**
  * A logged-in user's Markdown response (e.g. a *Registered*-only article) was cached under the page URL, and every anonymous visitor then received it with `X-Markdown-Cache: HIT`.
  * The cache is now read and written **only for anonymous `GET`/`HEAD` requests with HTTP 200**. Responses for logged-in users are sent with `Cache-Control: private, no-store`.

* **Non-Public Content in `/llms.txt`:**
  * Articles with a non-public access level, scheduled for the future or already expired were published in `/llms.txt`, with titles and intro text.
  * Menu items and articles are now limited to **guest-visible access levels** and to the `publish_up` / `publish_down` window. Gridbox pages and categories are filtered by `access` / `page_access` when those columns exist.

* **Admin Actions Accepted `GET`:**
  * `clear_logs` and `generate_llmstxt` now require `POST`, on top of the CSRF token and the `core.edit` permission on `com_plugins`. A `GET` request now returns `403`.
  * Removed a second "clear statistics" code path that changed data and redirected while the plugin settings form was being built.

* **Spoofable Analytics IP:**
  * `CF-Connecting-IP` and `X-Forwarded-For` were trusted unconditionally. They are now read only when `trust_proxy_headers` is enabled.

* **Stronger IPv6 Anonymisation:**
  * Only the last 16 bits of an IPv6 address were masked before. The stored value is now the `/48` prefix. IPv4 is unchanged (last octet masked).

* **Safer Rendering:**
  * Exclude selectors containing quotes are no longer turned into XPath.
  * Admin dashboard values are escaped consistently with `ENT_QUOTES`.
  * CSRF tokens are embedded in JavaScript through `json_encode()`.

---

### 🔄 Changed & Improved

* **Joomla 5/6 Forward Compatibility:**
  * Replaced every `Factory::getDbo()` call with the DI container / `DatabaseAwareTrait`, and every `$app->input` with `$app->getInput()`. Both old forms are deprecated in Joomla 6 and scheduled for removal in 7.0.
  * The service provider injects the dispatcher and the database through setters, while still supporting the Joomla 5 constructor signature.
  * Removed unused imports of `Joomla\CMS\Filesystem\*`, a namespace that no longer exists in Joomla 6.

* **Non-Blocking `/llms.txt` Regeneration:**
  * Regeneration no longer runs inside a visitor's request. It now runs in `onAfterRespond`, after the page has been delivered (`fastcgi_finish_request()` on PHP-FPM).
  * Concurrent regenerations are prevented with a lock, and the file is written atomically, so bots never download a truncated `/llms.txt`.

* **Installer:**
  * **Updates no longer re-enable the plugin** or reset its ordering. This now happens only on a fresh install; a disabled plugin stays disabled after an upgrade.
  * **Narrower cache clearing.** The installer no longer wipes the entire site and administrator cache or resets the whole OPcache, which affected other extensions and sites in the same PHP pool. It clears only the plugin cache, Joomla's page cache and the plugin's own OPcache entries.
  * **Uninstall cleans up.** It drops `#__aimarkdown_logs` and the plugin cache folder.
  * Minimum requirements are enforced: **PHP 8.2** and **Joomla 5.0**.
  * A failure to create the log table shows a warning instead of failing silently.

* **Cache Hygiene:**
  * Cache entries are written atomically (temp file + rename).
  * Expired entries are purged; previously every URL/config variant stayed on disk forever.
  * `libxml` error handling is restored after conversion.

* **Richer Markdown Structure:**
  * Ordered lists are numbered (`1.`, `2.`, …).
  * `<pre>` becomes fenced code and `<code>` becomes inline code.
  * Empty headings are dropped.
  * Relative links, images and PDF URLs are made absolute. Lazy-loaded images (`data-src`) are supported and `data:` images are skipped.

* **Database & Performance:**
  * Log timestamps are stored in **UTC** (`Factory::getDate()`) instead of the database server's `NOW()`, and displayed in the administrator's time zone.
  * Removed the MySQL-only `DATE_SUB(NOW(), …)` from queries.
  * The dashboard module and the settings dashboard use a single aggregated query for their counters (previously 3–4 queries).
  * `TRUNCATE` falls back to `DELETE` when the database user lacks the `DROP` privilege.
  * Stored URL and user agent are truncated in a UTF-8-safe way (`mb_strcut`).

* **`/llms.txt` Defaults:**
  * The default title is the **Site Name** from Global Configuration, instead of the hard-coded "Merdroid".

---

### 🛠️ Fixed

* **Gridbox Tabs and Accordions Never Unrolled:**
  * The XPath queries used `.*//…`, which XPath 1.0 reads as "context node *multiplied by* …", not as a path, so the whole union query failed.
  * Tab titles and accordion headings are now emitted as `### Heading` sections, as documented.

* **Text Containing `<` Was Deleted:**
  * A final `strip_tags()` removed technical content such as `<50 V` up to the next `>`. The call was removed, because the converter already produces plain text.
  * FAQ answers from JSON-LD are HTML-decoded **after** tag stripping, so `&lt;50 V` survives.

* **Wrong B2B Prices:**
  * Gridbox formats prices with non-breaking spaces, so `1&nbsp;234,56 zł` was read as **1.00**. Thousand separators (`1.234,56`, `1,234.56`) were also misparsed, and a missing number produced a price of `0.00`.
  * A dedicated price parser now handles non-breaking and thin spaces. Zero prices are ignored, and a net or gross value found on the page is no longer overwritten by the fallback calculation.

* **JSON-LD Ignored:**
  * The FAQ was ignored when the JSON-LD was a top-level array or `@type` was an array, and the plugin generated a synthetic FAQ instead.
  * JSON-LD is now parsed once per page and supports `@graph`, top-level arrays and array `@type`. Non-string values (e.g. a `Brand` object) no longer cause a `TypeError`.
  * Product metadata: `AggregateOffer.lowPrice` is used when `price` is missing, `http://schema.org/` availability URLs are normalised like `https://` ones, and `mpn` is used as a fallback for `sku`.

* **Query String Ignored by the Cache:**
  * `?page=2`, filters and non-SEF URLs (`index.php?option=…&id=…`) overwrote each other's cache entry.
  * The canonical URL, cache key and alternate link now include the normalised query string. The `output` / `markdown` switches are ignored and the parameters are sorted.

* **Wrong HTTP Status Codes:**
  * Every `HEAD` request returned `200 OK`, including URLs that do not exist, because the plugin closed the application before routing. `HEAD` is now handled by Joomla, so a `404` stays `404`.
  * The Markdown response always returned `200`. It now keeps the status set by the component.

* **Non-HTML Responses Modified:**
  * JSON, RSS, XML and raw responses are now left alone.
  * The plugin no longer sends a raw `Content-Type: text/html`, and the `Link` header is no longer sent twice.
  * `<link rel="alternate">` is inserted once, before the first `</head>`. Before, the check was case-insensitive while the replacement was case-sensitive, and every `</head>` string in the body was replaced.

* **Broken `/llms.txt` URLs:**
  * Links were built as `root + '/' + alias`, which returned `404` for most Joomla URLs. Menu items and articles are now routed through the Joomla router.
  * The home menu item points to the site root.
  * Separators, headings, URL and alias menu items are skipped.
  * `/llms.txt` is detected relative to the site root, so it also works for Joomla installed in a sub-folder, and root-relative paths no longer duplicate that sub-folder.

* **`/llms.txt` Rebuilt on Every Page View:**
  * If the web root was not writable, the whole generator (dozens of queries) ran on **every page view**. Attempts are now throttled to one per hour.

* **Layout of Nested Blocks:**
  * Headings from nested `<div>` blocks (all of Gridbox) were glued to the preceding text (`…text. ### Heading`). Block containers now keep paragraph breaks.
  * Leading indentation from the HTML source is trimmed, because 4+ spaces turned lines into Markdown code blocks. Fenced code is left untouched.

* **Smaller Fixes:**
  * PDF downloads are collected from the cleaned content only, no longer from the header, footer or menus.
  * Tab and accordion titles containing `&` are no longer double-escaped.
  * Markdown link text and URLs are escaped (`[`, `]`, spaces, parentheses).
  * YAML front matter strips control characters that are invalid in double-quoted scalars.
  * `/llms.txt` link titles are escaped, descriptions are HTML-decoded, and the reported file size is exact.
  * Plugin ID lookups also filter on `folder = system`.
  * User agent detection checks Opera before Chrome.

---

### ⬆️ Upgrade Notes

* Install `aimarkdown-1.5.2.zip` over 1.5.1. Settings are kept, and the Markdown cache is cleared automatically.
* **Behind Cloudflare or a reverse proxy:** enable *AI Analytics → Trust Proxy IP Headers*. Otherwise the proxy's address is logged.
* **Gridbox pages whose URLs are not `/{alias}`:** switch *LLMS.txt → Gridbox URL Mode* to **Joomla Router**.
* Log rows written by 1.5.1 used the database server's local time. Rows written from now on are UTC.

**Package:** `pkg_aimarkdown` 1.5.2 · `plg_system_aimarkdown` 1.5.2 · `com_aimarkdown` 1.5.2 · `mod_aimarkdown_dashboard` 1.5.2

## 1.5.1 — 2026-09-23

### 🚀 Added

* **Popularity-Based Content Prioritization (`llms_sort_by`):**
  * Added a setting allowing products (`#__gridbox_pages`) and articles (`#__content`) in `/llms.txt` to be ordered by **page impressions / view counts (`hits` / `views`)**.
  * Ensures that top-selling, most viewed, and highest-priority items are placed at the top of the `/llms.txt` file for LLM context windows.
  * Options: **Most Popular (By Views / Hits)** *(Default)* and **Newest First (By ID / Date)**.
  * Section title dynamically adjusts to `## Najpopularniejsze produkty i aparatura pomiarowa` when popularity sorting is active.

* **Configurable Catalog Link Limit (`llms_max_items`):**
  * Added an administrative setting to manage the total number of items indexed in `/llms.txt`:
    * `500` – Fast & Curated (Recommended by llmstxt.org)
    * `750` – Medium *(Default)*
    * `1000` – Extended
    * `2000` – Large
    * `0` – All Items (No Limit / 100% database catalog dump)

---

### 🔄 Changed & Improved

* **Three-Tier Category Description Resolution:**
  * **Tier 1:** Prioritizes authentic category `meta_description` or `description` stored in Gridbox.
  * **Tier 2:** Automatically generates unique, contextual descriptions incorporating the specific category title if no description is set.
  * **Tier 3:** Clean, token-efficient link formatting adhering to the [llmstxt.org](https://llmstxt.org) specification.
* **Token Optimization & Signal-to-Noise Ratio:**
  * Drastically reduced redundant token usage by eliminating static boilerplate sentences across dozens of category links.

---

### 🛠️ Fixed

* **Identical Category Link Descriptions:**
  * Fixed an issue where all Gridbox categories were outputting the same repetitive placeholder string (`: Kategoria projektów i materiałów.`).
* **Hardcoded Link Limit:**
  * Removed the hardcoded SQL `setLimit(500)` ceiling on Gridbox pages, allowing stores with thousands of products to index their entire catalog without truncation.

## 1.5.0 — 2026-09-23

### 🚀 Added

* **Standardized `/llms.txt` Domain Generator (llmstxt.org Specification):**
  * **Specification Compliance:** Generates and maintains a standardized `/llms.txt` Markdown file in the site root directory according to the [llmstxt.org](https://llmstxt.org) standard (H1 site title, blockquote summary, H2 structured sections).
  * **Comprehensive Content Extraction:** Gathers Core Pages (Joomla menu), Gridbox Categories, and all Gridbox items across apps (Case Studies, Tools, Blog posts, Store products, and Single Pages), plus standard Joomla articles (`#__content`).
  * **On-Demand Generation Interface (`src/Field/LlmsField.php`):** Added a dedicated **LLMS.txt** admin tab with a real-time AJAX generation button, animated progress bar, status indicators, file size, live link counter, and direct "View /llms.txt" preview button.
  * **Automated Background Scheduler:** Automated regeneration with configurable intervals: Every 12 Hours, Every 24 Hours (Daily), Every 5 Days, Every 15 Days, and Every 30 Days.
  * **URL Blacklist / Exclusion List:** Added multi-line textarea (`llms_exclude_urls`) to filter out specific URLs or paths (e.g., `/cart`, `/privacy-policy`, `/login`).
  * **Direct Routing & RFC 8288 Discovery:** Serves `/llms.txt` requests dynamically with `Content-Type: text/markdown` and advertises the file to crawlers via `Link: </llms.txt>; rel="service-desc"`.
  * **Disabled by Default:** The setting `enable_llmstxt` defaults to `0` (Off) for user safety.

* **Dedicated `/llms.txt` AI Crawler Analytics:**
  * Added a dedicated card to the **AI Analytics** dashboard tab tracking crawlers fetching `/llms.txt`.
  * Displays 30-day `/llms.txt` download volume, percentage distribution by bot with progress bars, and recent request logs (timestamp, bot name, masked IP).

* **Hybrid Product FAQ Engine (Human-Written Priority + Auto Fallback):**
  * **Schema.org & HTML Extraction:** Automatically detects and extracts custom FAQ data from Schema.org `FAQPage` JSON-LD (`mainEntity`) and `<details><summary>` markup already placed in product descriptions.
  * **HTML Clutter Purging:** Automatically strips raw `<details>`, `<summary>`, and `<h2>FAQ...</h2>` markup from the DOM, replacing them with a standardized, clean Markdown structure (`## Najczęściej zadawane pytania (FAQ)` with `### Question` and answer paragraphs).
  * **Contextual Fallback Generator:** When a product lacks a custom FAQ, the engine dynamically generates targeted Q&A pairs based on product attributes (official distribution warranty, calibration laboratory certificates, 24–48h delivery, and industry compliance).
  * **Configuration Toggles:** Added `enable_faq` (toggle) and `auto_generate_faq` (toggle, default: enabled).

* **B2B Price Anchoring (Explicit Netto / Brutto & VAT Separation):**
  * **Tax Discrimination:** Extracts both Net and Gross prices from Gridbox elements and Schema.org offers to prevent LLM price hallucinations with European/Polish VAT.
  * **Automated Calculation Fallback:** If only one rate is provided, the engine calculates the missing Net or Gross price using standard 23% VAT.
  * **Human & AI-Readable Formatting:** Formats prices cleanly in text (e.g., `4 890,00 PLN netto (6 014,70 PLN brutto, 23% VAT)`).
  * **Structured YAML Metadata:** Injects `price_net`, `price_gross`, `currency`, and `vat_rate` into YAML Frontmatter.
  * **New Dynamic CTA Tags:** Added `{price_net}` and `{price_gross}` placeholders for Merchant CTA templates.

* **Automated Blockquote Formatting for Merchant Header:**
  * The Header Authority Note (`merchant_header_text`) now automatically prefixes all content lines with Markdown blockquote syntax (`>`), removing the need to type it manually while preventing accidental double-quoting.

---

### 🔄 Changed & Improved

* **Default Author Visibility:**
  * Changed the default value of the `show_author` setting from `0` (No) to `1` (Yes) in `aimarkdown.xml` and runtime defaults.
* **Placeholder Hints Instead of Hardcoded Defaults:**
  * Replaced hardcoded default values across the **Merchant & CTA** and **LLMS.txt** tabs with clean placeholder hints (`hint="..."`), ensuring fields start empty until explicitly configured.
* **Simplified Analytics Status Badge:**
  * Removed the `(~15ms)` latency label from the cache indicator in the analytics dashboard, leaving a clean, compact `HIT` badge.
* **English Localization for Analytics UI:**
  * Standardized all newly added `/llms.txt` analytics elements, dashboard cards, and column headers into clean English.

---

### 🛠️ Fixed

* **Gridbox Schema Compatibility (`p.alias` SQL Error):**
  * Fixed an SQL error when generating `/llms.txt` on setups where `#__gridbox_pages` does not contain an `alias` column by dynamically inspecting table columns before querying.
* **Non-Product Gridbox Apps Indexing:**
  * Removed the restrictive `page_category = 'product'` SQL filter in `/llms.txt`, ensuring all Gridbox apps (Case Studies, Tools, Blog posts, Single Pages) are fully captured and indexed.
* **FAQ Content Duplication:**
  * Resolved an issue where existing HTML `<details>` and summary headings were duplicated inside the main body when parsing Gridbox descriptions.
* **Obsolete File Auto-Cleanup:**
  * Added automated removal of the legacy field file (`src/Field/LlmsGeneratorField.php`) during installation and updates via `script.php`.

## 1.4.1 — 2026-09-22

### 🚀 Added
* **Merchant Authority Injection & Custom CTA:**
  * Added a dedicated **Merchant & CTA** configuration tab in the plugin settings.
  * **Header Authority Note (Above Content):** Injects customizable distributor credentials, official partnership notices, and E-E-A-T trust signals directly below the YAML Frontmatter.
  * **Footer CTA & Ordering Details (Below Content):** Injects comprehensive ordering guidelines, contact info, lab calibration options, and direct purchase links at the bottom of the Markdown output.
  * **Dynamic Placeholder Tag Engine:** Automatically replaces variables in header and footer templates with actual page and product data:
    * `{title}` – Product / page title
    * `{sku}` – Catalog code / SKU number
    * `{price}` – Current price
    * `{currency}` – Currency (e.g., PLN, EUR)
    * `{availability}` – Stock status (e.g., InStock)
    * `{brand}` – Manufacturer / brand name
    * `{category}` – Category hierarchy
    * `{url}` – Fully qualified canonical URL
  * **Dynamic Tags Legend & GEO Guide:** Added an interactive, educational in-panel information box explaining Generative Engine Optimization (GEO) and providing ready-to-use template examples.
  * **YAML Frontmatter Seller Metadata:** Automatically enriches the frontmatter block with `seller` and `seller_type` attributes to explicitly establish merchant authority for LLMs (SearchGPT, Claude, Perplexity).

* **Joomla Package Architecture (`pkg_aimarkdown`):**
  * Converted the distribution model into an all-in-one native Joomla Package (`pkg_aimarkdown.xml`).
  * Bundles the system plugin, admin sidebar menu component, and dashboard module into a single atomic `.zip` installer.

* **Administrator Sidebar Menu Shortcut (`com_aimarkdown`):**
  * Added a native administrator proxy component (`com_aimarkdown`) that registers **AI Markdown for Gridbox** directly into the Joomla sidebar menu under **Components** with a dedicated robot icon (`class:robot`).
  * Provides one-click access to plugin configuration, custom exclusions, and analytics without searching through the Plugin Manager.

* **Home Dashboard Monitor Widget (`mod_aimarkdown_dashboard`):**
  * Added an administrator module for the Joomla 6 / Joomla 5 Home Dashboard (`cpanel` position).
  * Displays live AI crawler metrics directly on the welcome screen:
    * Visits in the last 24 hours.
    * 30-day cumulative AI traffic.
    * Cache Hit Rate percentage (`HIT` vs. `MISS`).
    * Name and timestamp of the most recent AI crawler visit.
    * Direct "Settings" shortcut button.

---

### 🔄 Changed & Improved
* **Extension Structure Modernization:**
  * Refactored menu integration and dashboard widgets out of procedural script hacks into standalone, native Joomla extensions managed by the package installer.
  * Cleaned manifest `<files>` declarations to prevent installer source copy errors.

---

### 🛠️ Fixed
* **Admin Sidebar Menu Display in Joomla 6:**
  * Resolved an issue where plugin-based menu injections were filtered out by Joomla's `mod_menu` query, which strictly requires extensions linked in the Components sidebar to be registered with `type = 'component'`.
* **Default Merchant Authority Injection & Custom CTA:**
  * Remove ‘Merserwis’ and ‘Laboratorium...’ as default options and leave them as example options.

## 1.3.3 — 2026-09-21

### 🚀 Added
* **Dynamic Client Identification (`Other: [Client Name]`):**
  * Replaced the generic fallback label (`Other AI / Custom Client`) with intelligent User-Agent parsing.
  * Captures and formats non-standard AI bots, CLI scrapers, and browsers as `Other: [Client]` (e.g., `Other: curl/8.4.0`, `Other: python-requests/2.31.0`, `Other: Chrome Browser`, `Other: DuckDuckBot`).
* **Expanded AI Crawler Patterns:**
  * Added detection signatures for OpenAI's dedicated SearchGPT crawler (`OAI-SearchBot`), Anthropic's search agent (`Claude-Search`), and Timpi search engine (`Timpibot`).
* **Interactive Analytics UI:**
  * URLs in the **Top Pages Crawled by AI** and **Latest AI Requests** tables are now clickable hyperlinks (`target="_blank"`), allowing administrators to quickly inspect the exact pages analyzed by bots.

---

### 🛠️ Fixed
* **Cleaning up AI bot statistics**
* **Cloudflare True Client IP Resolution:**
  * Fixed an issue where all visits logged Cloudflare's reverse-proxy IP addresses (`172.70.x.x`). The plugin now prioritizes `HTTP_CF_CONNECTING_IP` and `HTTP_X_FORWARDED_FOR` before falling back to `REMOTE_ADDR`.
* **PHP 8.5 DOM Traversal Fatal Error:**
  * Fixed a potential fatal error in `unrollGridboxAccordions()` by ensuring `instanceof \DOMElement` is verified before calling `getAttribute('class')`, preventing calls to undefined methods on `DOMDocument` or root text nodes.
* **Vary Header Compression Loss:**
  * Fixed an issue where `header('Vary: Accept')` replaced existing server headers, which could strip `Vary: Accept-Encoding` and disable Gzip/Brotli compression in reverse proxies. Now uses `header('Vary: Accept', false)` to preserve upstream compression rules.
* **YAML Frontmatter Syntax Corruption:**
  * Fixed a bug where titles or categories containing unescaped line breaks (`\n`) or unescaped double quotes broke YAML parsers. Added strict whitespace normalization and quote escaping (`\\\\`, `\"`).
* **PDF URL Parsing for Hashes & Protocol-Relative Paths:**
  * Extended the PDF link detector to capture URLs with hash fragments (e.g., `document.pdf#page=2`) and properly resolve protocol-relative URLs (`//cdn.example.com/...`).

---

### 🔄 Changed & Improved
* **Automatic Cache Invalidation on Settings Change:**
  * The cache key now incorporates an 8-character hash of the active plugin configuration (`$configSignature`). Saving changes in the plugin settings (e.g., toggling Frontmatter, modifying custom exclude selectors) now immediately invalidates old cache files without requiring a manual cache purge.
* **Enhanced Access Control:**
  * Added permission validation (`core.edit` on `com_plugins`) in `AnalyticsField.php` to ensure only authorized administrators can view or trigger the log truncation action.

## 1.3.2 — 2026-09-21

### 🚀 Added
* **Clear Statistics Action:**
  * Added a dedicated **"Clear Statistics"** button directly to the AI Analytics dashboard in the Joomla backend.
  * Implemented secure database log truncation (`TRUNCATE` / `DELETE` on `#__aimarkdown_logs`) protected by native Joomla CSRF session tokens and a JavaScript confirmation prompt.
* **Configurable Analytics Display Limit:**
  * Added the `analytics_display_limit` parameter in the plugin configuration (`aimarkdown.xml`) with selectable values: **5, 10 (Default), 15, 30**.
  * Dynamically controls the number of rows shown in:
    * **Top Pages Crawled by AI** list.
    * **Latest AI Requests** inspection table.
* **Extension Project Homepage in Update Manager (`<infourl>`):**
  * Added the `<infourl>` tag to the update manifest (`update.xml`) pointing to `https://github.com/merserwis/plg_system_aimarkdown/`.
  * Administrators can now click "More Info" directly from the Joomla Update Manager (**System → Update → Extensions**) to visit the official GitHub repository.
* **In-Panel Support & Info Banner:**
  * Added an informational card in the plugin configuration settings linking to the GitHub repository, maintainer contact email (`a.blazewicz@merserwis.pl`), and license terms.
* **AGPL-3.0 License & Contact Metadata:**
  * Formally declared `AGPL-3.0-or-later` in `aimarkdown.xml` and in all PHP source file DocBlocks (`AiMarkdown.php`, `AnalyticsField.php`, `provider.php`, `script.php`).
  * Added official maintainer email (`a.blazewicz@merserwis.pl`) and copyright notice.

---

### 🔄 Changed & Improved
* **Token Counter Header Normalization (`x-markdown-tokens`):**
  * Verified and standardized the token estimation header to lowercase **`x-markdown-tokens`** across both **Cache HIT** and **Cache MISS** execution paths, complying with HTTP/2, HTTP/3, and Cloudflare AI Crawl Control specifications.
* **Update Server Endpoint Migration:**
  * Switched the live update server endpoint to the official GitHub Raw path:
    `https://raw.githubusercontent.com/merserwis/plg_system_aimarkdown/refs/heads/main/update.xml`

---

### 🔒 Security
* Enforced strict CSRF token validation (`Session::checkToken('get')`) before allowing database logs to be purged via the administrator interface.

## 1.3.1 — 2026-09-21

- Change update url

## 1.3.0 — 2026-09-21

- Local AI Bot Visit Analytics (Privacy-First Dashboard)
- Universal PDF Documentation & Datasheet Prioritization
- Balbooa Gridbox Tab & Accordion Unrolling
- Custom Exclude Selectors (No-Code Filtering)
