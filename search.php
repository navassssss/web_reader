<?php
require_once 'functions.php';
enforce_auth();

$id = $_GET['id'] ?? '';
$query = get_url_from_shortcode($id) ?? '';
$error = null;
$results = [];

if (!empty($query)) {
    $search_url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);
    $response = fetch_with_cache($search_url, 'search_ddg', 30, false);

    if (is_array($response) && isset($response['error'])) {
        $error = $response['error'];
    } elseif ($response) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $response, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//div[contains(@class, "result__body")]');
        
        foreach ($nodes as $node) {
            $titleNode = $xpath->query('.//h2[@class="result__title"]/a', $node)->item(0);
            $snippetNode = $xpath->query('.//a[@class="result__snippet"]', $node)->item(0);
            
            if ($titleNode) {
                $title = $titleNode->textContent;
                $rawHref = $titleNode->getAttribute('href');
                
                $realUrl = '';
                if (strpos($rawHref, 'uddg=') !== false) {
                    $parts = parse_url($rawHref);
                    if (isset($parts['query'])) {
                        parse_str($parts['query'], $qVars);
                        if (isset($qVars['uddg'])) {
                            $realUrl = $qVars['uddg'];
                        }
                    }
                }
                
                if (!empty($realUrl)) {
                    $results[] = [
                        'title' => trim($title),
                        'url' => trim($realUrl),
                        'description' => $snippetNode ? trim($snippetNode->textContent) : ''
                    ];
                }
            }
        }
        
        if (empty($results) && strpos($response, 'duckduckgo.com') === false) {
             $error = 'Failed to parse search results from DuckDuckGo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            color: #111;
            background: #fff;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #121212; color: #eee; }
        }
        
        .header {
            border-bottom: 1px solid #ddd;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        @media (prefers-color-scheme: dark) { .header { border-color: #333; } }
        
        form { margin-top: 15px; }
        input[type="text"] {
            width: 70%;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        button {
            padding: 10px 15px;
            font-size: 16px;
            background: #eee;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        @media (prefers-color-scheme: dark) {
            input[type="text"] { background: #222; border-color: #444; color: #fff; }
            button { background: #333; border-color: #444; color: #eee; }
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        @media (prefers-color-scheme: dark) {
            .error { background: #4a0000; color: #ffcccc; }
        }

        .result-card {
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            background: #fdfdfd;
        }
        @media (prefers-color-scheme: dark) {
            .result-card { background: #1e1e1e; border-color: #333; }
        }
        
        .result-title {
            font-size: 1.2em;
            margin: 0 0 5px 0;
        }
        .result-title a {
            color: #1a0dab;
            text-decoration: none;
        }
        .result-title a:hover { text-decoration: underline; }
        
        .result-url {
            color: #006621;
            font-size: 0.9em;
            margin-bottom: 8px;
            word-break: break-all;
        }
        @media (prefers-color-scheme: dark) {
            .result-title a { color: #8ab4f8; }
            .result-url { color: #81c995; }
        }
        
        .result-desc {
            color: #444;
            font-size: 0.95em;
            margin-bottom: 15px;
        }
        @media (prefers-color-scheme: dark) { .result-desc { color: #ccc; } }

        .btn-read {
            display: inline-block;
            background: #111;
            color: #fff;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 20px;
            font-size: 0.9em;
            margin-right: 10px;
        }
        .btn-read:hover { background: #333; text-decoration: none; }
        
        .btn-orig {
            color: #666;
            text-decoration: none;
            font-size: 0.9em;
        }
        .btn-orig:hover { text-decoration: underline; }

        @media (prefers-color-scheme: dark) {
            .btn-read { background: #ddd; color: #111; }
            .btn-read:hover { background: #fff; }
            .btn-orig { color: #aaa; }
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="index.php" style="text-decoration:none; color:#666; font-size:1.2em;">&larr; Home</a>
        <form action="index.php" method="POST">
            <input type="text" name="q" value="">
            <button type="submit">Search</button>
        </form>
    </div>

    <div class="content">
        <?php if ($error): ?>
            <div class="error">
                <strong>Error:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($results) && !$error && !empty($query)): ?>
            <div style="text-align: center; color: #666; padding: 40px 0;">
                No results found for "<?= htmlspecialchars($query) ?>"
            </div>
        <?php endif; ?>

        <div>
            <?php foreach ($results as $item): ?>
                <?php 
                    $url = $item['url'] ?? '';
                    $title = $item['title'] ?? $url;
                    $desc = $item['description'] ?? '';
                    $code = get_shortcode_for_url($url);
                    $read_url = 'read.php?id=' . $code;
                ?>
                <div class="result-card">
                    <h2 class="result-title">
                        <a href="<?= htmlspecialchars($read_url) ?>">
                            <?= htmlspecialchars($title) ?>
                        </a>
                    </h2>
                    <div class="result-url"><?= htmlspecialchars($url) ?></div>
                    <div class="result-desc"><?= htmlspecialchars($desc) ?></div>
                    <div>
                        <a href="<?= htmlspecialchars($read_url) ?>" class="btn-read">Read Article</a>
                        <a href="<?= htmlspecialchars($url) ?>" class="btn-orig">Original source &rarr;</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
