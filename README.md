# System - AI Markdown for Balbooa Gridbox (Joomla 5 & 6)

[![Joomla Version](https://img.shields.io/badge/Joomla-5.x%20%7C%206.x-blue?style=for-the-badge&logo=joomla)](https://www.joomla.org)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20--%208.5%2B-777BB4?style=for-the-badge&logo=php)](https://www.php.net)
[![Standards](https://img.shields.io/badge/Standards-RFC%208288%20%7C%20RFC%209110-orange?style=for-the-badge)](https://datatracker.ietf.org/doc/html/rfc8288)
[![Version](https://img.shields.io/badge/Release-v1.3.0-brightgreen?style=for-the-badge)](https://github.com/)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green?style=for-the-badge)](LICENSE)

A high-performance, native Joomla system plugin designed to serve clean, formatting-stripped, machine-readable **Markdown** directly to modern AI search agents (**SearchGPT, OpenAI GPTBot, Anthropic Claude, Perplexity AI, Google Gemini, Apple Intelligence**). 

Fully optimized for **Balbooa Gridbox** (Store, Pages & Blog), eliminating template bloat and providing **Generative Engine Optimization (GEO)** at $0 cost (a self-hosted, superior alternative to Cloudflare Pro's $20/mo *Markdown for Agents*).

---

## ⚡ Key Highlights

* **HTTP Content Negotiation (RFC 9110):** Automatically detects `Accept: text/markdown` from AI crawlers and returns clean text without layout noise.
* **RFC 8288 Web Linking:** Broadcasts `rel="alternate"` and `rel="canonical"` headers for agent discovery and SEO protection.
* **Ultra-Fast Cache Engine (~15ms TTFB):** Intercepts requests on `onAfterInitialise`, bypassing database queries, component routing, and template rendering for cached pages.
* **YAML Frontmatter for E-Commerce:** Automatically extracts Schema.org JSON-LD and Gridbox Store data (Title, SKU, Brand, Net/Gross Prices, Stock Availability, Category) into structured YAML metadata.
* **Balbooa Gridbox Tab & Accordion Unrolling:** Automatically extracts content from collapsed/hidden tabs (`.ba-item-tabs`) and accordions, converting them into sequential headings (`###`).
* **Universal PDF Prioritization:** Harvests all downloadable technical datasheets and manuals, converts them to absolute HTTPS URLs, and compiles a dedicated `## Downloads & Documentation` section.
* **Custom Exclude Selectors (No-Code):** Strip arbitrary elements using simple CSS classes (`.promo-box`), IDs (`#chat-widget`), or XPath queries via Joomla admin settings.
* **Embedded AI Analytics Dashboard:** Privacy-first, 100% local database logging of incoming AI crawlers with a native Bootstrap 5 dashboard in Joomla backend.
* **Zero Remote Telemetry:** Completely autonomous; no tracking data ever leaves your server.

---

## 🛠️ How It Works

```text
Incoming Request
  │
  ├─► Method: HEAD (HTML) ───────────► Instant Discovery Headers (~5ms) ──► Exit
  │
  ├─► Method: GET (HTML) ────────────► Append RFC 8288 Link & <head> tags ──► Serve HTML
  │
  └─► Accept: text/markdown OR ?output=markdown
        │
        ├─► Cache HIT ───────────────► Serve Cached Markdown (~15ms TTFB) ──► Exit
        │
        └─► Cache MISS
              │
              ├─► Render Page Buffer
              ├─► Extract Schema.org JSON-LD (Product Metadata)
              ├─► Extract PDF Datasheets & Manuals
              ├─► Unroll Gridbox Tabs & Accordions
              ├─► Strip Layout, Scripts, Styles, Modals & Custom Selectors
              ├─► Generate YAML Frontmatter + Clean Markdown
              ├─► Save to /cache/plg_system_aimarkdown/
              └─► Log AI Bot Visit Locally & Return with RFC 8288 Headers
```

---

## 📦 What the Output Looks Like

When an AI crawler queries a product page:

```markdown
---
title: "Gossen Metrawatt METRAHIT ENERGY Power Multimeter"
type: "product"
sku: "M249A"
brand: "Gossen Metrawatt"
price: "5400.00"
currency: "PLN"
availability: "InStock"
category: "Portable Meters > Multimeters"
url: "https://www.example.com/products/multimeters/metrahit-energy"
downloads:
  - "https://www.example.com/images/pdf/datasheet-metrahit-energy-en.pdf"
  - "https://www.example.com/images/pdf/manual-metrahit-energy-en.pdf"
---

# Gossen Metrawatt METRAHIT ENERGY Power Multimeter

Professional multimeter designed for high-precision power and energy measurement.

### Technical Specifications
| Parameter | Range | Resolution | Accuracy |
| --- | --- | --- | --- |
| Voltage AC/DC | 600.00 V | 10 mV | ±(0.05% + 3 digits) |
| Current AC/DC | 10.000 A | 1 mA | ±(0.2% + 5 digits) |
| Power Active | 6000.0 W | 0.1 W | ±(0.25% + 5 digits) |

### Standard Accessories
* Set of safety measurement cables
* DAkkS / PCA calibration certificate
* Protective rubber holster

## Downloads & Documentation
* [Product Datasheet (PDF)](https://www.example.com/images/pdf/datasheet-metrahit-energy-en.pdf)
* [User Manual (PDF)](https://www.example.com/images/pdf/manual-metrahit-energy-en.pdf)
```

---

## 🚀 Installation

1. Download the latest release `.zip` from the [Releases](https://github.com/) section.
2. In your Joomla Administrator panel, navigate to:  
   **System → Install → Extensions**.
3. Drag & drop the `.zip` package.
4. The installer script will automatically:
   * Position the plugin at the very end of the system execution queue.
   * Enable the plugin.
   * Create the local MySQL table `#__aimarkdown_logs`.
   * Clear all Joomla and OPcache buffers.

---

## ⚙️ Configuration

Go to **System → Plugins → System - AI Markdown for Gridbox**:

| Tab | Setting | Default | Description |
| :--- | :--- | :--- | :--- |
| **Settings** | **Enable YAML Frontmatter** | `Yes` | Prepends structured YAML metadata at the top of Markdown. |
| **Settings** | **Prioritize PDF Downloads** | `Yes` | Harvests PDF datasheets and appends a dedicated `## Downloads & Documentation` section. |
| **Settings** | **Extract Tabs & Accordions** | `Yes` | Unrolls hidden Balbooa Gridbox tabs and accordions into sequential headings. |
| **Settings** | **Enable Markdown Cache** | `Yes` | Caches Markdown to disk, reducing response times from ~2.5s to ~15ms. |
| **Settings** | **Cache Lifetime** | `24 Hours` | Expiration window before regenerating cached Markdown. |
| **Settings** | **Custom Exclude Selectors** | *Empty* | Custom CSS selectors (`.promo`, `#chat`) or XPath queries to strip. |
| **Analytics** | **Enable AI Analytics** | `Yes` | Logs AI crawler visits locally in the Joomla database. |
| **Analytics** | **Log Retention** | `30 Days` | Automatically purges logs older than the specified threshold. |
| **Analytics** | **AI Analytics Dashboard** | — | Real-time Bootstrap 5 dashboard showing AI visits, hit rates, and top crawled pages. |

---

## 🧪 Verification & Testing

### 1. Test Content Negotiation (AI Crawler Emulation)
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
X-Markdown-Tokens: 842
```

### 2. Fast In-Browser Preview
Append `?output=markdown` to any URL in your browser:
```text
https://www.example.com/your-product?output=markdown
```

### 3. Verify RFC 8288 Headers on Standard HTML Responses
```bash
curl -I https://www.example.com/
```
*Expected Response:*
```http
HTTP/1.1 200 OK
Content-Type: text/html; charset=utf-8
Vary: Accept
Link: <https://www.example.com/?output=markdown>; rel="alternate"; type="text/markdown", <https://www.example.com/kontakt>; rel="service-doc", <https://www.example.com/robots.txt>; rel="describedby"
```

---

## 🔒 Security & SEO Safeguards

* **Cache Poisoning Defense:** Sends `Vary: Accept` across all HTML and Markdown responses, ensuring reverse proxies (Cloudflare) maintain isolated caches for human visitors and AI crawlers.
* **Canonical Protection:** Emits RFC 8288 `rel="canonical"` headers pointing back to original HTML URLs to prevent duplicate content indexing in Google.
* **CRLF Sanitization:** All canonical URLs injected into HTTP headers are sanitized against header-splitting vulnerabilities.
* **Zero Leakage:** Form wrappers, hidden CSRF tokens, session inputs, and sensitive developer comments are stripped prior to conversion.

---

## 📋 Requirements

* **Joomla:** 5.0 - 6.x (Fully native Joomla 6 Dependency Injection Architecture)
* **PHP:** 8.2 - 8.5+ (Strict typing, zero deprecated functions, safe numeric entity encoding)
* **Extensions Supported:** Balbooa Gridbox 2.x (Store, Blog, Custom Fields), Native Joomla Content (`com_content`)

---

## 📄 License
This project is open-source software licensed under the [GNU General Public License v2.0 or later](LICENSE).
```
