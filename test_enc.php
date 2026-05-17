<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'functions.php';

$words = ['test1', 'test2'];
echo "Original words: " . json_encode($words) . "\n";

$encrypted = encrypt_blacklist($words);
echo "Encrypted data: " . $encrypted . "\n";

file_put_contents(BLACKLIST_FILE, $encrypted);
echo "Written to " . BLACKLIST_FILE . "\n";

$decrypted = decrypt_blacklist();
echo "Decrypted words: " . json_encode($decrypted) . "\n";
