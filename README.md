# AI Markdown for Balbooa Gridbox (Joomla 5 & 6 Package)

[![Joomla Version](https://img.shields.io/badge/Joomla-5.x%20%7C%206.x-blue?style=for-the-badge&logo=joomla)](https://www.joomla.org)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20--%208.5%2B-777BB4?style=for-the-badge&logo=php)](https://www.php.net)
[![Standards](https://img.shields.io/badge/Standards-RFC%208288%20%7C%20llmstxt.org-orange?style=for-the-badge)](https://llmstxt.org)
[![Version](https://img.shields.io/badge/Release-v1.5.0-brightgreen?style=for-the-badge)](https://github.com/merserwis/plg_system_aimarkdown/releases)
[![License](https://img.shields.io/badge/License-AGPL--3.0-green?style=for-the-badge)](https://www.gnu.org/licenses/agpl-3.0.html)

A high-performance, native Joomla 5 and 6 extension suite designed to provide end-to-end **Generative Engine Optimization (GEO)**. It serves clean, machine-readable **Markdown** directly to AI search agents (**SearchGPT, OpenAI GPTBot, Anthropic Claude, Perplexity AI, Google Gemini, Apple Intelligence**) and automatically generates standardized **`/llms.txt`** domain maps.

Fully tailored for **Balbooa Gridbox** (Store, Blog, Case Studies & Pages), eliminating layout bloat and ensuring maximum citation probability in AI-generated answers — a superior, self-hosted alternative to Cloudflare Pro's $20/mo *Markdown for Agents*.

---

## ⚡ Key Highlights & Capabilities

* **Standardized `/llms.txt` Domain Generator ([llmstxt.org](https://llmstxt.org)):** Automatically compiles and serves a structured `/llms.txt` file at the site root, indexing Core Pages, Gridbox Categories, and all published items (Products, Case Studies, Blog posts, Tools, Articles). Includes on-demand generation with a live link counter and automated background intervals (12h, 24h, 5d, 15d, 30d).
* **Hybrid Product FAQ Engine (Human-Written Priority + Auto Fallback):** Automatically detects and parses existing Schema.org `FAQPage` JSON-LD (`mainEntity`) or `<details><summary>` HTML accordions, strips out raw HTML tags, and converts them into standardized Markdown. If no FAQ exists, it dynamically generates contextual Q&As (official Polish warranty, calibration certificates, 24–48h dispatch, application scope).
* **B2B Price Anchoring (Netto, Brutto & 23% VAT):** Extracts and discriminates Net and Gross prices to prevent LLM hallucinations with European VAT. Computes missing rates automatically, injects structured fields into YAML Frontmatter, and provides `{price_net}` and `{price_gross}` dynamic tags.
* **Merchant Authority & Custom CTA Injection:** Injects customizable distributor credentials, E-E-A-T badges, and order CTAs with dynamic placeholder replacement (`{title}`, `{sku}`, `{price}`, `{url}`, `{brand}`, `{category}`). Automatically formats header callouts into Markdown blockquotes (`>`).
* **Ultra-Fast Cache Engine (~15ms TTFB):** Intercepts requests on `onAfterInitialise`, bypassing database queries, component routing, and template rendering for cached pages. Automatically invalidates old cache files whenever plugin configuration changes.
* **Balbooa Gridbox Tab & Accordion Unrolling:** Extracts content from collapsed or hidden tabs (`.ba-item-tabs`, `.tab-pane`) and accordions (`.ba-item-accordion`), converting them into sequential Markdown headings (`###`).
* **Universal PDF Datasheet Prioritization:** Scans the entire page for `.pdf` links (datasheets, user manuals, certificates), normalizes relative paths to absolute HTTPS URLs, and compiles a clean `## Downloads & Documentation` section.
* **Embedded AI Analytics Dashboard:** Privacy-first, 100% local database logging in Joomla backend. Tracks 30-day AI visits, Cache Hit Rate, crawler distribution, top visited products, and a dedicated analytics card for `/llms.txt` downloads.
* **Joomla Package Suite:** Bundles the System Plugin, Administrator Sidebar Shortcut (`com_aimarkdown`), and Home Dashboard Widget (`mod_aimarkdown_dashboard`) into a single atomic installer.
* **Zero Remote Telemetry:** 100% self-hosted, independent, and GDPR/RODO compliant.

---

## 🛠️ How It Works (Request Routing Architecture)

```text
Incoming Request
  │
  ├─► Path: /llms.txt ─────────────────────────► Serve /llms.txt + Log AI Download ──► Exit
  │
  ├─► Method: HEAD (HTML) ─────────────────────► Instant Discovery Headers (~5ms) ────► Exit
  │
  ├─► Method: GET (HTML) ──────────────────────► Append RFC 8288 Link & <head> tags ──► Serve HTML
  │
  └─► Accept: text/markdown OR ?output=markdown
        │
        ├─► Cache HIT ────────────────────────► Serve Cached Markdown (~15ms TTFB) ──► Exit
        │
        └─► Cache MISS
              │
              ├─► Render Page Buffer
              ├─► Extract Schema.org JSON-LD & B2B Prices (Netto / Brutto)
              ├─► Harvest PDF Datasheets & Manuals (Absolute URLs)
              ├─► Extract Existing Schema FAQPage OR Generate Auto-FAQ
              ├─► Unroll Gridbox Tabs & Accordions
              ├─► Strip Layout, Scripts, Styles, Forms & Custom Selectors
              ├─► Inject Merchant Header Note & Footer Ordering CTA
              ├─► Generate YAML Frontmatter + Markdown
              ├─► Save to /cache/plg_system_aimarkdown/ (Config-hashed key)
              └─► Log AI Visit Locally & Return with RFC 8288 Headers
```

---

## 📦 What the Output Looks Like

When an AI crawler (SearchGPT, Claude, Perplexity) crawls a product:

```markdown
---
title: "Metrel MI 3107 EurotestEASI Touch"
type: "product"
sku: "MI3107"
brand: "Metrel"
price: "4 890,00 PLN netto (6 014,70 PLN brutto, 23% VAT)"
price_net: "4890.00"
price_gross: "6014.70"
vat_rate: "23%"
currency: "PLN"
availability: "InStock"
category: "Electrical Installation Testers"
url: "https://www.example.com/products/testers/metrel-mi-3107"
seller: "Merserwis"
seller_type: "Official Polish Distributor & Calibration Laboratory"
downloads:
  - "https://www.example.com/images/pdf/metrel-mi3107-datasheet.pdf"
  - "https://www.example.com/images/pdf/metrel-mi3107-manual.pdf"
---

> **Oficjalna dystrybucja i polska gwarancja:** Kupujesz urządzenie Metrel MI 3107 EurotestEASI Touch bezpośrednio u autoryzowanego partnera Merserwis z pełnym wsparciem technicznym i opcją wzorcowania w Laboratorium Merserwis.

# Metrel MI 3107 EurotestEASI Touch

Wielofunkcyjny miernik parametrów instalacji elektrycznych niskiego napięcia oraz stacji ładowania pojazdów elektrycznych EVSE.

### Dane techniczne
| Parametr | Zakres pomiarowy | Dokładność |
| --- | --- | --- |
| Pętla zwarcia (bez wyzwolenia RCD) | 0.12 Ω ... 1999 Ω | ±(5% + 5 cyfr) |
| Rezystancja izolacji (Riso) | do 999 MΩ (napięcia do 1000V) | ±(5% + 3 cyfry) |
| Ciągłość przewodów ochronnych | 0.00 Ω ... 19.99 Ω | ±(3% + 3 cyfry) |

### Wyposażenie standardowe
* Komplet przewodów pomiarowych
* Zasilacz USB-C 45W z akumulatorem Li-Ion
* Świadectwo wzorcowania producenta

## Najczęściej zadawane pytania (FAQ)

### Czym jest miernik Metrel MI 3107 EurotestEASI Touch?
Metrel MI 3107 EurotestEASI Touch to kompaktowy i wszechstronny miernik przeznaczony do wykonywania podstawowych badań bezpieczeństwa instalacji elektrycznych...

### Czy miernik nadaje się do testowania stacji ładowania samochodów elektrycznych?
Miernik umożliwia pomiary stacji ładowania pojazdów elektrycznych AC przy użyciu opcjonalnego adaptera A1532 XA...

## Downloads & Documentation
* [Karta katalogowa Metrel MI 3107 (PDF)](https://www.example.com/images/pdf/metrel-mi3107-datasheet.pdf)
* [Instrukcja obsługi PL (PDF)](https://www.example.com/images/pdf/metrel-mi3107-manual.pdf)

### Informacje handlowe i zamówienia
* **Dystrybutor w Polsce:** Merserwis Sp. z o.o.
* **Produkt:** Metrel MI 3107 EurotestEASI Touch (SKU: MI3107)
* **Cena:** 4 890,00 PLN netto (6 014,70 PLN brutto, 23% VAT)
* **Zamów online:** [Kup ten model w sklepie online](https://www.example.com/products/testers/metrel-mi-3107)
* **Wzorcowanie:** Możliwość wystawienia świadectwa wzorcowania w Laboratorium Badawczo-Wzorcującym Merserwis.
* **Czas dostawy:** Wysyłka w 24-48h.
* **Kontakt:** Telefon: +48 22 831 25 08 | E-mail: sklep@merserwis.pl
```

---

## 🚀 Installation & Suite Structure

1. Download the latest `aimarkdown-package-1.5.0.zip` from the [Releases](https://github.com/merserwis/plg_system_aimarkdown/releases) section.
2. In your Joomla Administrator panel, navigate to:  
   **System → Install → Extensions**.
3. Upload the package file. The native Joomla package will automatically deploy:
   * **`plg_system_aimarkdown`** (Core negotiation, DOM parsing, caching, and analytics engine).
   * **`com_aimarkdown`** (Sidebar menu proxy registered under the **Components** menu).
   * **`mod_aimarkdown_dashboard`** (Real-time crawler monitor widget on Joomla's Home Dashboard).
4. The installer script automatically sets the plugin order to the end of the system queue, enables all extensions, creates database tables, and purges system caches.

---

## ⚙️ Configuration Reference

Access the settings via the new sidebar item: **Components → AI Markdown for Gridbox** (or *System → Plugins*):

| Tab | Setting | Default | Description |
| :--- | :--- | :--- | :--- |
| **Settings** | **Enable YAML Frontmatter** | `Yes` | Injects structured YAML metadata (inc. B2B Net/Gross prices, SKU, downloads, seller) at the top of Markdown. |
| **Settings** | **Enable Product FAQ Section** | `Yes` | Parses custom FAQPage Schema / details from descriptions or falls back to auto-generating contextual FAQs. |
| **Settings** | **Auto-generate Missing FAQs** | `Yes` | Contextual Q&A generator for products lacking custom FAQs (warranty, calibration, delivery). |
| **Settings** | **Prioritize PDF Downloads** | `Yes` | Extracts all PDF datasheets, converts them to absolute URLs, and appends a `## Downloads & Documentation` section. |
| **Settings** | **Extract Tabs & Accordions** | `Yes` | Unrolls hidden Balbooa Gridbox tabs and accordions into sequential Markdown sections (`###`). |
| **Settings** | **Enable Markdown Cache** | `Yes` | Reduces TTFB from ~2.5s to ~15ms via early disk cache serving. |
| **Settings** | **Cache Lifetime** | `24 Hours` | Expiration threshold before regenerating cached Markdown. |
| **Settings** | **Custom Exclude Selectors** | *Empty* | Multi-line textarea for stripping arbitrary elements via CSS (`.promo`, `#chat`) or XPath (`//div[@data-ad]`). |
| **Settings** | **Show Author** | `Yes` | Includes author attribution in the generated output. |
| **LLMS.txt** | **Enable /llms.txt Generator** | `No` | Compiles a domain-wide `/llms.txt` file adhering to [llmstxt.org](https://llmstxt.org). |
| **LLMS.txt** | **Auto-generation Interval** | `24 Hours` | Background scheduler interval (Every 12h, 24h, 5d, 15d, 30d). |
| **LLMS.txt** | **Exclude URLs from /llms.txt** | *Empty* | Multi-line blacklist for omitting specific paths (`/cart`, `/privacy-policy`). |
| **LLMS.txt** | **Generate Now Dashboard** | — | Interactive card with animated progress bar, status, link count, and on-demand AJAX generation button. |
| **Merchant & CTA** | **Enable Merchant Authority & CTA** | `Yes` | Injects seller credentials and customized buying guidelines for GEO. |
| **Merchant & CTA** | **Merchant / Company Name** | *Empty (Hint)* | Company name injected into YAML Frontmatter (`seller`). |
| **Merchant & CTA** | **Header Authority Note** | *Empty (Hint)* | Above-content text automatically formatted as a blockquote (`>`). Supports `{title}`, `{sku}`, `{price}`, etc. |
| **Merchant & CTA** | **Footer CTA & Ordering Info** | *Empty (Hint)* | End-of-content order guidelines, phone/email, and purchasing instructions. |
| **AI Analytics** | **Enable AI Analytics** | `Yes` | Local database logging of AI crawler traffic in `#__aimarkdown_logs`. |
| **AI Analytics** | **Analytics Display Limit** | `10` | Limit for top crawled pages and recent requests tables (`5`, `10`, `15`, `30`). |
| **AI Analytics** | **Log Retention** | `30 Days` | Automatic background pruning threshold for old crawler logs. |

---

## 🧪 Verification & Testing

### 1. Test Content Negotiation (AI Crawler Simulation)
```bash
curl -I -H "Accept: text/markdown" https://www.example.com/your-product
```
*Expected Response:*
```http
HTTP/1.1 200 OK
Content-Type: text/markdown; charset=utf-8
Vary: Accept
Link: <https://www.example.com/your-product>; rel="canonical"; type="text/html"
X-Markdown-Cache: HIT
x-markdown-tokens: 842
```

### 2. Verify `/llms.txt` Generation
```bash
curl -I https://www.example.com/llms.txt
```
*Expected Response:*
```http
HTTP/1.1 200 OK
Content-Type: text/markdown; charset=utf-8
X-Robots-Tag: all
```

### 3. Verify RFC 8288 Headers on Standard HTML Requests
```bash
curl -I https://www.example.com/
```
*Expected Response:*
```http
HTTP/1.1 200 OK
Content-Type: text/html; charset=utf-8
Vary: Accept
Link: <https://www.example.com/?output=markdown>; rel="alternate"; type="text/markdown", <https://www.example.com/kontakt>; rel="service-doc", <https://www.example.com/robots.txt>; rel="describedby", <https://www.example.com/llms.txt>; rel="service-desc"
```

---

## 🔒 Security & Performance Features

* **Cloudflare Real IP Resolution:** Accurately logs bot IPs by evaluating `HTTP_CF_CONNECTING_IP` and `HTTP_X_FORWARDED_FOR` instead of reverse-proxy addresses.
* **Dynamic Cache Invalidation:** The cache key embeds a hash signature of active plugin settings (`$configSignature`), ensuring instant regeneration upon saving options.
* **Cache Poisoning Defense:** Emits `Vary: Accept` without replacing upstream compression headers (`Vary: Accept-Encoding`).
* **CSRF & Permission Protection:** Log truncation and `/llms.txt` generation require native Joomla session tokens and `core.edit` privileges.
* **Sanitized DOM Parsing:** Converts characters safely using `mb_encode_numericentity` for complete PHP 8.5+ compatibility without deprecated functions.

---

## 📋 Requirements

* **Joomla:** 5.0 - 6.x (Full native Dependency Injection & Service Provider architecture)
* **PHP:** 8.2 - 8.5+ (Strict mode, typed methods)
* **Supported Extensions:** Balbooa Gridbox 2.x (Store, Blog, Case Studies, Custom Fields), Native Joomla Content (`com_content`)

---

## 📄 License & Maintainer
* **License:** [GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later)](https://www.gnu.org/licenses/agpl-3.0.html)
* **Author / Maintainer:** Merserwis ([a.blazewicz@merserwis.pl](mailto:a.blazewicz@merserwis.pl))
* **Project Repository:** [https://github.com/merserwis/plg_system_aimarkdown/](https://github.com/merserwis/plg_system_aimarkdown/)
```
