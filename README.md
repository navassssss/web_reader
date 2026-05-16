# Minimalist Web Reader

A highly secure, zero-JS, distraction-free web proxy and reading environment heavily optimized for E-ink devices like the Amazon Kindle. It completely bypasses aggressive browser caching and features a robust server-side authentication gateway.

## Key Features

* **Two Modes of Operation**:
    * **Reader Mode**: Extracts only the pure article text/content for a distraction-free experience. Uses a fast native DOM extractor (Tier 1) and falls back to Jina Reader API (Tier 2) for JS-heavy sites.
    * **Web Mode**: Acts as a lightweight proxy, preserving all site navigation, menus, pagination, and links while stripping away heavy CSS, videos, and dangerous scripts.
* **Aggressive Anti-Caching Security**: Enforces strict server-side PIN authentication. Session rotation and token invalidation prevent older Kindle browsers from replaying back-button cache. 
* **Two-Phase Loading Architecture**: Instantly serves a lightweight loading shell for cache-misses while fetching content in the background. Prevents Kindle browser timeouts.
* **Zero Client-Side JS**: Entirely rendered server-side. Perfect for low-power, low-memory E-ink browsers.
* **URL Obfuscation**: Internal URLs are converted into short hashes, hiding your browsing targets from network observers and history states.

## Installation

1. Clone or upload this repository to your PHP server (e.g., XAMPP, Apache).
2. Ensure the `cache/` directory has write permissions (it will be created automatically if not present).
3. Create a `.env` file in the root directory:

```env
JINA_API_KEY=your_jina_api_key_here
READER_PIN=your_secret_pin
```

4. You can get a free Jina API key at [jina.ai](https://jina.ai/) if you don't have one.

## Security Overview

- **.env Protection**: A pre-configured `.htaccess` file prevents any web access to your `.env` and configuration files.
- **Single Active Session**: Logging into the reader immediately rotates the server token, instantly kicking out any other active sessions on other devices.
- **Cloudflare Compatible**: Built-in HTTP headers aggressively enforce `no-store` and `no-cache`, effectively forcing Cloudflare edge nodes and the Kindle's BFCache to re-validate authentication on every page visit.

## Usage

1. Navigate to the reader's URL in your device's browser.
2. Enter your PIN.
3. Use the search bar (powered anonymously by DuckDuckGo HTML) to find topics or paste direct URLs.
4. Toggle between `[ Reader ]` and `[ Web ]` in the top navigation bar while reading any article.
