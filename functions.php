<?php
// Load .env variables
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $_ENV[trim($parts[0])] = trim($parts[1], '"\' ');
        }
    }
}

// Configuration
define('CACHE_DIR', __DIR__ . '/cache/');
define('CACHE_EXPIRATION', 3600 * 24); // 24 hours
define('JINA_API_KEY', $_ENV['JINA_API_KEY'] ?? '');

// Security Configuration
define('READER_PIN', $_ENV['READER_PIN'] ?? '0000'); // Default PIN if missing
define('BLACKLIST_KEY', $_ENV['BLACKLIST_KEY'] ?? 'default_key');
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('LINKS_DIR', CACHE_DIR . 'links/');
define('AUTH_TOKEN_FILE', CACHE_DIR . 'auth_token.txt');
define('BLACKLIST_FILE', CACHE_DIR . 'blacklist.enc');

// Ensure directories exist
if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
if (!is_dir(LINKS_DIR)) mkdir(LINKS_DIR, 0755, true);

// Start Session securely
session_start();

// Prevent Cloudflare and browser caching to guarantee PIN lock on every hit
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 19 Nov 1981 08:52:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");

// --- Token helpers ---

// Returns the current valid server token (creates one on first run)
function get_server_token() {
    if (file_exists(AUTH_TOKEN_FILE)) {
        return trim(file_get_contents(AUTH_TOKEN_FILE));
    }
    return rotate_server_token();
}

// Writes a brand-new random token to the file and returns it
function rotate_server_token() {
    $token = bin2hex(random_bytes(16)); // 32-char random hex
    file_put_contents(AUTH_TOKEN_FILE, $token);
    return $token;
}

function enforce_auth() {
    // GET ?logout=1 → rotate the server token; Kindle's stale cookie becomes invalid instantly
    if (isset($_GET['logout'])) {
        rotate_server_token();
        session_unset();
        // Explicitly expire the session cookie in the browser
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '',
                time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        header('Location: index.php');
        exit;
    }

    $auth_ok = false;
    if (
        isset($_SESSION['logged_in'], $_SESSION['token'], $_SESSION['last_activity']) &&
        $_SESSION['logged_in'] === true
    ) {
        // Check inactivity timeout
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            session_unset();
            session_destroy();
        }
        // Check token still matches server (catches Kindle stale-cookie after logout)
        elseif ($_SESSION['token'] !== get_server_token()) {
            session_unset();
            session_destroy();
        } else {
            $_SESSION['last_activity'] = time();
            $auth_ok = true;
        }
    }

    $error = null;
    if (!$auth_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
        if ($_POST['pin'] === READER_PIN) {
            // Rotate token on every login — invalidates any existing session on other devices
            $token = rotate_server_token();
            session_regenerate_id(true); // Prevent session fixation
            $_SESSION['logged_in'] = true;
            $_SESSION['token'] = $token;
            $_SESSION['last_activity'] = time();
            // Always redirect to home after login, never trust REQUEST_URI
            header('Location: index.php');
            exit;
        } else {
            $error = "Incorrect PIN";
        }
    }

    if (!$auth_ok) {
        render_login_form($error);
        exit; // Explicit safety net — never fall through
    }
}

function render_login_form($error) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Document</title>
        <style>
            body { font-family: sans-serif; text-align: center; margin-top: 50px; background: #fff; color: #111; }
            input[type="password"] { padding: 10px; font-size: 16px; border: 1px solid #ccc; width: 200px; text-align: center; }
            button { padding: 10px 20px; font-size: 16px; margin-top: 10px; cursor: pointer; }
            .err { color: red; margin-bottom: 10px; }
            @media (prefers-color-scheme: dark) { body { background: #121212; color: #eee; } input, button { background: #333; color: #eee; border: 1px solid #555; } }
        </style>
    </head>
    <body>
        <form method="POST">
            <?php if ($error) echo "<div class='err'>$error</div>"; ?>
            <input type="password" name="pin" placeholder="Enter PIN" autofocus autocomplete="off"><br>
            <button type="submit">Unlock</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// URL Obfuscation Functions
function get_shortcode_for_url($url) {
    // Generate a deterministic 6-character code
    $hash = substr(md5($url . "s3cr3t_s4lt"), 0, 6);
    $file = LINKS_DIR . $hash . '.txt';
    if (!file_exists($file)) {
        file_put_contents($file, $url);
    }
    return $hash;
}

function get_url_from_shortcode($code) {
    if (!preg_match('/^[a-f0-9]{6}$/i', $code)) return null;
    $file = LINKS_DIR . $code . '.txt';
    if (file_exists($file)) {
        return file_get_contents($file);
    }
    return null;
}

// --- Blacklist Functions ---
function encrypt_blacklist($array) {
    $json = json_encode(array_values(array_unique(array_filter($array))));
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    // Hash key to exactly 32 bytes (256 bits) required for AES-256
    $key = hash('sha256', BLACKLIST_KEY, true);
    $encrypted = openssl_encrypt($json, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($encrypted . '::' . base64_encode($iv));
}

function decrypt_blacklist() {
    if (!file_exists(BLACKLIST_FILE)) return [];
    $data = base64_decode(file_get_contents(BLACKLIST_FILE));
    if (strpos($data, '::') === false) return [];
    list($encrypted_data, $iv_b64) = explode('::', $data, 2);
    $iv = base64_decode($iv_b64);
    $key = hash('sha256', BLACKLIST_KEY, true);
    $decrypted = openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
    return $decrypted ? json_decode($decrypted, true) : [];
}

function filter_blocked_words($html) {
    if (empty($html)) return $html;
    $words = decrypt_blacklist();

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    // 1. Always remove images and image wrappers
    $remove_tags = ['img', 'picture', 'figure'];
    foreach ($remove_tags as $tag) {
        $nodes = $dom->getElementsByTagName($tag);
        $to_remove = [];
        foreach ($nodes as $n) $to_remove[] = $n;
        foreach ($to_remove as $n) {
            if ($n->parentNode) $n->parentNode->removeChild($n);
        }
    }

    // 2. Filter blocked words from text nodes (if any words exist)
    if (!empty($words)) {
        $xpath = new DOMXPath($dom);
        $textNodes = $xpath->query('//text()');

        foreach ($textNodes as $node) {
            $text = $node->nodeValue;
            if (trim($text) === '') continue;
            
            $replaced = false;
            foreach ($words as $word) {
                if (stripos($text, $word) !== false) {
                    $text = str_ireplace($word, '', $text);
                    $replaced = true;
                }
            }
            if ($replaced) {
                $node->nodeValue = $text;
            }
        }
    }

    $filtered = $dom->saveHTML();
    return str_replace('<?xml encoding="utf-8"?>', '', $filtered);
}

/**
 * Fetch URL using cURL with caching
 */
function fetch_with_cache($url, $cache_prefix, $timeout = 60, $json_response = false) {
    $cache_key = md5($url);
    $cache_file = CACHE_DIR . $cache_prefix . '_' . $cache_key;

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < CACHE_EXPIRATION) {
        $data = file_get_contents($cache_file);
        return $json_response ? json_decode($data, true) : $data;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $headers = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'];
    if ($json_response) $headers[] = 'Accept: application/json';
    if (defined('JINA_API_KEY') && JINA_API_KEY !== '') {
        $headers[] = 'Authorization: Bearer ' . JINA_API_KEY;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['error' => 'Connection error: ' . $error];
    if ($http_code >= 400) return ['error' => "HTTP Error $http_code."];

    if ($response !== false) file_put_contents($cache_file, $response);
    return $json_response ? json_decode($response, true) : $response;
}

// ---------------------------------------------------------------------------
// Two-Tier Article Extraction
// Tier 1: Native PHP (cURL + DOMDocument) — fast, free, zero API cost
// Tier 2: Jina Reader API fallback — for JS-heavy sites Tier 1 can't handle
// ---------------------------------------------------------------------------

/**
 * Fetch raw HTML for a URL using cURL.
 */
function fetch_raw_html($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_ENCODING, '');  // Auto-handle gzip / deflate / br
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($html && $code < 400) ? $html : null;
}

/**
 * Tier 1: Extract article content natively using DOMDocument.
 * Returns HTML string or null if content is too thin / extraction fails.
 */
function extract_article_native($url) {
    $html = fetch_raw_html($url);
    if (!$html) return null;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Extract best page title (prefer og:title)
    $page_title = '';
    $og = $xpath->query('//meta[@property="og:title"]/@content');
    if ($og && $og->length > 0) {
        $page_title = trim($og->item(0)->value);
    } else {
        $t = $dom->getElementsByTagName('title')->item(0);
        if ($t) $page_title = trim($t->textContent);
    }

    // Remove noisy tags entirely
    $remove_tags = ['script', 'style', 'nav', 'header', 'footer',
                    'aside', 'iframe', 'noscript', 'form', 'button',
                    'svg', 'figure > figcaption'];
    foreach ($remove_tags as $tag) {
        $nodes = $dom->getElementsByTagName(explode(' ', $tag)[0]);
        $to_remove = [];
        foreach ($nodes as $n) $to_remove[] = $n;
        foreach ($to_remove as $n) {
            if ($n->parentNode) $n->parentNode->removeChild($n);
        }
    }

    // Remove noisy elements by class/id keywords
    $noise = ['nav', 'menu', 'sidebar', 'footer', 'header', 'advertisement',
              'cookie', 'popup', 'modal', 'share', 'social', 'comment',
              'related', 'newsletter', 'subscribe', 'widget', 'banner', 'promo'];
    foreach ($noise as $kw) {
        try {
            $found = $xpath->query(
                "//*[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'$kw') or " .
                "contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'$kw')]"
            );
            $to_remove = [];
            foreach ($found as $n) $to_remove[] = $n;
            foreach ($to_remove as $n) {
                if ($n->parentNode) $n->parentNode->removeChild($n);
            }
        } catch (Exception $e) { /* ignore bad xpath */ }
    }

    // Try semantic content containers in priority order
    $selectors = [
        '//article',
        '//main',
        '//*[@role="main"]',
        '//*[contains(@class,"article-body")]',
        '//*[contains(@class,"article-content")]',
        '//*[contains(@class,"post-content")]',
        '//*[contains(@class,"entry-content")]',
        '//*[contains(@class,"post-body")]',
        '//*[contains(@class,"story-body")]',
        '//*[contains(@class,"article")]',
        '//*[contains(@class,"content")]',
        '//*[contains(@id,"article")]',
        '//*[contains(@id,"content")]',
    ];

    $best = null;
    $best_len = 0;
    foreach ($selectors as $sel) {
        try {
            $nodes = $xpath->query($sel);
            if (!$nodes) continue;
            foreach ($nodes as $n) {
                $len = strlen(trim($n->textContent));
                if ($len > $best_len) { $best_len = $len; $best = $n; }
            }
        } catch (Exception $e) { continue; }
        if ($best && $best_len > 800) break; // Good enough
    }

    // Fallback to body
    if (!$best || $best_len < 200) {
        $best = $dom->getElementsByTagName('body')->item(0);
        $best_len = $best ? strlen(trim($best->textContent)) : 0;
    }

    // If still too thin, signal failure so Jina is used
    if (!$best || $best_len < 300) return null;

    $inner = '';
    foreach ($best->childNodes as $child) {
        $inner .= $dom->saveHTML($child);
    }

    $h1 = $page_title ? '<h1>' . htmlspecialchars($page_title) . '</h1>' : '';
    return $h1 . $inner;
}

/**
 * Main article fetcher.
 * Checks cache first. If miss: tries Tier 1 (native), then Tier 2 (Jina).
 * Always stores rendered HTML in cache (not raw Markdown).
 * Returns HTML string or ['error' => '...'].
 */
function fetch_article_html($url) {
    $cache_key  = md5($url);
    $cache_file = CACHE_DIR . 'article_' . $cache_key;

    // Serve from cache if available
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < CACHE_EXPIRATION) {
        return file_get_contents($cache_file);
    }

    // --- Tier 1: Native extraction ---
    $html = extract_article_native($url);
    $tier = 1;

    // --- Tier 2: Jina Reader fallback ---
    if (!$html) {
        $tier       = 2;
        $reader_url = 'https://r.jina.ai/' . $url;
        $markdown   = fetch_with_cache($reader_url, 'jina_raw', 60, false);
        if (is_array($markdown) && isset($markdown['error'])) {
            return $markdown;
        }
        require_once __DIR__ . '/Parsedown.php';
        $parsedown = new Parsedown();
        $parsedown->setSafeMode(true);
        $html = $parsedown->text($markdown);
    }

    $html = filter_blocked_words($html);

    // Prepend tier marker so the caller can show which method was used
    $output = "<!--tier:$tier-->" . $html;

    if ($output) file_put_contents($cache_file, $output);

    return $output ?: ['error' => 'Could not extract article content.'];
}

/**
 * Web Mode: Fetch a full webpage and simplify it for Kindle.
 * Keeps ALL links, navigation, menus, pagination, footers.
 * Only removes dangerous/heavy elements: script, style, iframe, video, etc.
 * Returns cleaned HTML or ['error' => '...'].
 */
function simplify_webpage($url) {
    $cache_key  = md5($url . '_webmode');
    $cache_file = CACHE_DIR . 'web_' . $cache_key;

    // Serve from cache if available
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < CACHE_EXPIRATION) {
        return file_get_contents($cache_file);
    }

    // Always use Jina Reader — handles JS-rendered sites reliably
    $reader_url = 'https://r.jina.ai/' . $url;
    $markdown   = fetch_with_cache($reader_url, 'jina_raw', 60, false);

    if (is_array($markdown) && isset($markdown['error'])) {
        return $markdown;
    }
    if (empty($markdown)) {
        return ['error' => 'Could not retrieve page content.'];
    }

    require_once __DIR__ . '/Parsedown.php';
    $parsedown = new Parsedown();
    $parsedown->setSafeMode(true);
    $html = $parsedown->text($markdown);

    $html = filter_blocked_words($html);

    if ($html) file_put_contents($cache_file, $html);

    return $html ?: ['error' => 'Could not render page content.'];
}


