<?php
require_once 'functions.php';
enforce_auth();

$id   = $_GET['id'] ?? '';
$mode = ($_GET['mode'] ?? 'reader') === 'web' ? 'web' : 'reader';
$url  = get_url_from_shortcode($id);
$error        = null;
$html_content = '';
$title        = 'Document';

// Helper: build a reader URL preserving current mode
function reader_url($id, $mode, $extra = '') {
    return 'read.php?id=' . urlencode($id) . '&mode=' . $mode . ($extra ? '&' . $extra : '');
}

// Helper: resolve + rewrite a link href through our proxy
function rewrite_href($href, $base_url, $mode) {
    if (empty($href) || strpos($href, '#') === 0) return $href;
    if (strpos($href, 'http') !== 0 && strpos($href, '//') !== 0) {
        $parsed = parse_url($base_url);
        $base   = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');
        if (strpos($href, '/') === 0) {
            $href = $base . $href;
        } else {
            $path = $parsed['path'] ?? '/';
            $dir  = dirname($path);
            if ($dir === '\\') $dir = '/';
            $href = rtrim($base, '/') . rtrim($dir, '/') . '/' . $href;
        }
    }
    return 'read.php?id=' . get_shortcode_for_url($href) . '&mode=' . $mode;
}

if (!$url) {
    $error = 'Invalid or expired document link.';
} else {
    // Each mode has its own cache
    $cache_prefix = ($mode === 'web') ? 'web_'  : 'article_';
    $cache_key    = ($mode === 'web') ? md5($url . '_webmode') : md5($url);
    $cache_file   = CACHE_DIR . $cache_prefix . $cache_key;
    $cached       = file_exists($cache_file) && (time() - filemtime($cache_file)) < CACHE_EXPIRATION;

    // --- Phase 1: cache miss → send loading shell immediately ---
    if (!$cached && !isset($_GET['loading'])) {
        $loading_url = reader_url($id, $mode, 'loading=1');
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="6;url=<?= htmlspecialchars($loading_url) ?>">
    <title>Document</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding: 60px 20px; background: #fff; color: #111; }
        @media (prefers-color-scheme: dark) { body { background: #121212; color: #eee; } }
        p { font-size: 1.1em; color: #666; }
        @media (prefers-color-scheme: dark) { p { color: #aaa; } }
    </style>
</head>
<body>
    <p>Loading<?= $mode === 'web' ? ' page' : ' article' ?>&hellip;</p>
    <p style="font-size:0.85em;">This may take a few seconds.</p>
</body>
</html>
        <?php
        if (ob_get_level()) ob_end_flush();
        flush();
        // Warm the cache in background
        if ($mode === 'web') simplify_webpage($url);
        else                 fetch_article_html($url);
        exit;
    }

    // --- Phase 2 (or warm cache): fetch, rewrite links, render ---
    $raw_html = ($mode === 'web') ? simplify_webpage($url) : fetch_article_html($url);

    // Extract tier marker from Reader mode (<!--tier:N-->)
    $tier = null;
    if ($mode === 'reader' && is_string($raw_html)) {
        if (preg_match('/^<!--tier:(\d+)-->/', $raw_html, $m)) {
            $tier     = (int) $m[1];
            $raw_html = substr($raw_html, strlen($m[0]));
        }
    }

    if (is_array($raw_html) && isset($raw_html['error'])) {
        $error = $raw_html['error'];
    } elseif (!empty($raw_html)) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $raw_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Rewrite all links to stay inside the reader (preserving mode)
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (!empty($href) && strpos($href, '#') !== 0) {
                $link->setAttribute('href', rewrite_href($href, $url, $mode));
                $link->removeAttribute('target');
            }
        }

        // In Web mode also rewrite image src to absolute (so relative images load)
        if ($mode === 'web') {
            $parsed = parse_url($url);
            $origin = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');
            $imgs = $dom->getElementsByTagName('img');
            foreach ($imgs as $img) {
                $src = $img->getAttribute('src');
                if ($src && strpos($src, 'http') !== 0 && strpos($src, '//') !== 0 && strpos($src, 'data:') !== 0) {
                    $img->setAttribute('src', $origin . (strpos($src, '/') === 0 ? $src : '/' . $src));
                }
            }
        }

        $html_content = $dom->saveHTML();
        $html_content = str_replace('<?xml encoding="utf-8"?>', '', $html_content);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        body {
            font-family: Georgia, "Times New Roman", serif; /* Perfect for Kindle */
            line-height: 1.6;
            max-width: 700px;
            margin: 0 auto;
            padding: 20px;
            color: #111;
            background: #fff;
            font-size: 18px; /* Slightly larger for e-ink readability */
        }
        @media (prefers-color-scheme: dark) {
            body { background: #121212; color: #eee; }
        }
        
        .nav-bar {
            border-bottom: 1px solid #ddd;
            padding-bottom: 15px;
            margin-bottom: 30px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 14px;
        }
        @media (prefers-color-scheme: dark) { .nav-bar { border-color: #333; } }
        
        .nav-bar a {
            color: #666;
            text-decoration: none;
            margin-right: 15px;
        }
        .nav-bar a:hover { text-decoration: underline; }
        .nav-url { color: #999; word-break: break-all; }
        
        @media (prefers-color-scheme: dark) {
            .nav-bar a { color: #aaa; }
            .nav-url { color: #777; }
        }

        .error {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 4px;
            font-family: sans-serif;
        }
        @media (prefers-color-scheme: dark) {
            .error { background: #4a0000; color: #ffcccc; }
        }

        /* Markdown Content Styles */
        h1, h2, h3, h4, h5, h6 {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #000;
            margin-top: 1.5em;
            margin-bottom: 0.5em;
            line-height: 1.2;
        }
        @media (prefers-color-scheme: dark) {
            h1, h2, h3, h4, h5, h6 { color: #fff; }
        }
        
        h1 { font-size: 2.2em; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        @media (prefers-color-scheme: dark) { h1 { border-color: #333; } }
        
        a { color: #1a0dab; text-decoration: none; }
        a:hover { text-decoration: underline; }
        @media (prefers-color-scheme: dark) { a { color: #8ab4f8; } }
        
        img { max-width: 100%; height: auto; display: block; margin: 20px auto; }
        
        blockquote {
            border-left: 4px solid #ccc;
            margin: 0;
            padding-left: 16px;
            color: #555;
            font-style: italic;
        }
        @media (prefers-color-scheme: dark) {
            blockquote { border-color: #555; color: #aaa; }
        }
        
        pre {
            background: #f4f4f4;
            padding: 15px;
            overflow-x: auto;
            font-family: monospace;
            font-size: 14px;
            border-radius: 4px;
        }
        code {
            background: #f4f4f4;
            padding: 2px 4px;
            font-family: monospace;
            font-size: 14px;
            border-radius: 3px;
        }
        @media (prefers-color-scheme: dark) {
            pre, code { background: #222; }
        }
        
        hr {
            border: 0;
            border-top: 1px solid #ddd;
            margin: 40px 0;
        }
        @media (prefers-color-scheme: dark) { hr { border-color: #333; } }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th { background: #f9f9f9; }
        @media (prefers-color-scheme: dark) {
            th, td { border-color: #444; }
            th { background: #222; }
        }
    </style>
</head>
<body>
    <div class="nav-bar">
        <a href="index.php">&larr; Home</a>
        <?php if ($url): ?>
        <span style="margin-left:10px;">
            <a href="<?= htmlspecialchars(reader_url($id, 'reader')) ?>"<?= $mode === 'reader' ? ' style="font-weight:bold;text-decoration:underline;"' : '' ?>>[ Reader ]</a>
            &nbsp;
            <a href="<?= htmlspecialchars(reader_url($id, 'web')) ?>"<?= $mode === 'web' ? ' style="font-weight:bold;text-decoration:underline;"' : '' ?>>[ Web ]</a>
        </span>
        <?php if ($mode === 'reader' && $tier): ?>
        <span style="margin-left:12px; color:#999; font-size:0.8em;">
            <?= $tier === 1 ? '&#9889; Native' : '&#9729; Jina' ?>
        </span>
        <?php endif; ?>
        <?php endif; ?>
        <a href="?logout=1" style="float: right;">[ Lock ]</a>
    </div>

    <main>
        <?php if ($error): ?>
            <div class="error">
                <strong>Error:</strong> <?= $error ?>
            </div>
        <?php else: ?>
            <div class="content">
                <?= $html_content ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
