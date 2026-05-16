<?php
require_once 'functions.php';
enforce_auth();

if (isset($_POST['q'])) {
    $q = trim($_POST['q']);
    if (empty($q)) {
        header('Location: index.php');
        exit;
    }
    
    // Check if it's a valid URL or looks like one
    if (filter_var($q, FILTER_VALIDATE_URL) || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]/i', $q)) {
        if (!preg_match('~^(?:f|ht)tps?://~i', $q)) {
            $q = 'http://' . $q;
        }
        $code = get_shortcode_for_url($q);
        header('Location: read.php?id=' . $code);
        exit;
    } else {
        $code = get_shortcode_for_url($q);
        header('Location: search.php?id=' . $code);
        exit;
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
            padding: 40px 20px;
            color: #111;
            background: #fff;
            text-align: center;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #121212; color: #eee; }
        }
        h1 {
            font-family: Georgia, serif;
            font-weight: normal;
            font-size: 3em;
            margin-bottom: 10px;
        }
        p { color: #666; margin-bottom: 40px; }
        @media (prefers-color-scheme: dark) { p { color: #aaa; } }
        
        .search-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 600px;
            margin: 0 auto;
        }
        input[type="text"] {
            width: 100%;
            padding: 16px;
            font-size: 18px;
            border: 1px solid #ccc;
            border-radius: 8px;
            box-sizing: border-box;
        }
        @media (prefers-color-scheme: dark) {
            input[type="text"] { background: #222; border-color: #444; color: #fff; }
        }
        button {
            padding: 16px;
            font-size: 18px;
            background: #eee;
            border: 1px solid #ccc;
            border-radius: 8px;
            cursor: pointer;
        }
        @media (prefers-color-scheme: dark) {
            button { background: #333; border-color: #444; color: #eee; }
        }
        /* Kindle specific fallback for flexbox */
        .search-container {
            display: block;
        }
        button { width: 100%; margin-top: 10px; }
    </style>
</head>
<body>
    <div style="text-align: right; padding: 10px;">
        <a href="?logout=1" style="color: #666; text-decoration: none;">[ Lock ]</a>
    </div>
    <h1>Reader</h1>
    <p>Paste a link or search the web for distraction-free reading.</p>

    <form action="index.php" method="POST">
        <div class="search-container">
            <input 
                type="text" 
                name="q" 
                placeholder="Enter URL or search query..."
                autocomplete="off"
                autofocus
            >
            <button type="submit">Read / Search</button>
        </div>
    </form>
</body>
</html>
