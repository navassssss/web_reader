# Universal Web Reader

A minimalist, universally accessible web reader application focused entirely on a clean reading experience.

## Features

- **Distraction-Free Reading**: Clean typography, optimal line-spacing, and centered column for long-form reading.
- **Search Integration**: Uses Jina's search API to find articles across the web.
- **Smart Routing**: Paste a URL to read it instantly, or type a query to search.
- **Dark Mode**: Toggleable dark/light themes for comfortable reading in any environment.
- **Lightweight Architecture**: No complex frontend frameworks. Runs on simple PHP and minimal vanilla JavaScript (using CDN libraries for Markdown parsing).
- **Fast & Efficient**: Built-in filesystem caching for instant load times on repeated reads.

## File Structure

```text
web_reader/
├── index.php      # Main search/URL input interface
├── search.php     # Handles web search and displays results
├── read.php       # Fetches and renders articles in reader mode
├── functions.php  # Shared utilities (cURL fetching, caching, config)
└── cache/         # Writable directory for cached API responses
```

## Deployment Instructions

This application is designed to be as simple to deploy as possible. It runs on almost any modern PHP hosting environment (Shared Hosting, VPS, Docker, etc.).

### Prerequisites
- PHP 7.4 or higher
- PHP cURL extension enabled

### Setup Steps

1. **Upload Files**
   Upload all files (`index.php`, `search.php`, `read.php`, `functions.php`) to your web server's document root or a subdirectory (e.g., `/public_html/reader/`).

2. **Configure Permissions**
   The application uses a filesystem cache to store fetched articles and search results. The `cache/` folder is automatically created, but you must ensure your web server has permissions to write to it.
   ```bash
   chmod -R 755 cache/
   # OR if necessary:
   chmod -R 777 cache/
   ```

3. **Configure API Key (Optional but recommended)**
   The Jina Search API (`s.jina.ai`) often requires an API key for access. You can get one from the [Jina AI Platform](https://jina.ai/).
   - Open `functions.php`.
   - Locate the line `define('JINA_API_KEY', '');`
   - Paste your API key inside the quotes.

4. **Access the Application**
   Visit the URL where you deployed the files (e.g., `http://yourdomain.com/reader/`) and start reading!

## Technical Stack
- **Backend**: Vanilla PHP with cURL.
- **Styling**: Tailwind CSS (via CDN).
- **Markdown Rendering**: marked.js (via CDN).
- **Sanitization**: DOMPurify (via CDN).
