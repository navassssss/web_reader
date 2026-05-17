<?php
require_once 'functions.php';
enforce_auth();

$message = '';
$unlocked_words = null;

// Add a new word
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_word'])) {
    $new_word = trim($_POST['new_word']);
    if ($new_word !== '') {
        $words = decrypt_blacklist();
        $words[] = $new_word;
        file_put_contents(BLACKLIST_FILE, encrypt_blacklist($words));
        
        // Clear caches so changes apply immediately
        array_map('unlink', glob(CACHE_DIR . 'article_*') ?: []);
        array_map('unlink', glob(CACHE_DIR . 'web_*') ?: []);
        
        $message = "Word added securely. Caches cleared.";
    }
}

// Unlock list for viewing/deleting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_key'])) {
    if ($_POST['unlock_key'] === BLACKLIST_KEY) {
        $unlocked_words = decrypt_blacklist();
    } else {
        $message = "Incorrect Decryption Key.";
    }
}

// Delete a word
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_index']) && isset($_POST['unlock_key_re'])) {
    if ($_POST['unlock_key_re'] === BLACKLIST_KEY) {
        $words = decrypt_blacklist();
        $index = (int)$_POST['delete_index'];
        if (isset($words[$index])) {
            unset($words[$index]);
            file_put_contents(BLACKLIST_FILE, encrypt_blacklist($words));
            
            array_map('unlink', glob(CACHE_DIR . 'article_*') ?: []);
            array_map('unlink', glob(CACHE_DIR . 'web_*') ?: []);
            
            $message = "Word deleted.";
            $unlocked_words = decrypt_blacklist(); // refresh list
        }
    } else {
         $message = "Session expired or invalid key. Please unlock again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Minimalist Web Reader</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #fff; color: #111; }
        @media (prefers-color-scheme: dark) { body { background: #121212; color: #eee; } }
        .nav-bar { border-bottom: 1px solid #ddd; padding-bottom: 10px; margin-bottom: 20px; }
        @media (prefers-color-scheme: dark) { .nav-bar { border-color: #333; } }
        .card { border: 1px solid #ccc; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        @media (prefers-color-scheme: dark) { .card { border-color: #444; background: #1e1e1e; } }
        input[type="text"], input[type="password"] { padding: 8px; width: calc(100% - 20px); margin-bottom: 10px; border: 1px solid #ccc; border-radius: 3px; }
        @media (prefers-color-scheme: dark) { input[type="text"], input[type="password"] { background: #333; color: #fff; border-color: #555; } }
        button { padding: 8px 15px; cursor: pointer; background: #eee; border: 1px solid #ccc; border-radius: 3px; }
        @media (prefers-color-scheme: dark) { button { background: #333; color: #fff; border-color: #555; } }
        .msg { color: #d32f2f; font-weight: bold; margin-bottom: 15px; }
        @media (prefers-color-scheme: dark) { .msg { color: #ff6b6b; } }
        .word-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        @media (prefers-color-scheme: dark) { .word-item { border-color: #333; } }
        form { margin: 0; }
        a { color: #0066cc; text-decoration: none; }
        @media (prefers-color-scheme: dark) { a { color: #4da6ff; } }
    </style>
</head>
<body>
    <div class="nav-bar">
        <a href="index.php">&larr; Home</a>
        <a href="?logout=1" style="float: right;">[ Lock ]</a>
    </div>

    <h2>Admin Panel</h2>
    
    <?php if ($message): ?>
        <div class="msg"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>Add Blocked Word</h3>
        <p style="font-size: 0.85em; color: #666;">Words added here will be silently removed from all articles. The list is stored securely via AES-256 encryption.</p>
        <form method="POST">
            <input type="text" name="new_word" placeholder="Word or phrase to remove..." required autocomplete="off">
            <button type="submit">Encrypt & Add</button>
        </form>
    </div>

    <div class="card">
        <h3>Manage Blocked Words</h3>
        <?php if ($unlocked_words === null): ?>
            <p style="font-size: 0.85em; color: #666;">Enter your <strong>BLACKLIST_KEY</strong> to decrypt and view the current list.</p>
            <form method="POST">
                <input type="password" name="unlock_key" placeholder="Decryption Key..." required>
                <button type="submit">Unlock List</button>
            </form>
        <?php else: ?>
            <p style="font-size: 0.85em; color: #666;">List decrypted successfully. Do not leave this page open.</p>
            <?php if (empty($unlocked_words)): ?>
                <p><em>The list is empty.</em></p>
            <?php else: ?>
                <?php foreach ($unlocked_words as $index => $word): ?>
                    <div class="word-item">
                        <span><?= htmlspecialchars($word) ?></span>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="unlock_key_re" value="<?= htmlspecialchars($_POST['unlock_key'] ?? $_POST['unlock_key_re']) ?>">
                            <input type="hidden" name="delete_index" value="<?= $index ?>">
                            <button type="submit" style="color:#d32f2f; background:none; border:none; text-decoration:underline; font-size: 1em;">Delete</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
