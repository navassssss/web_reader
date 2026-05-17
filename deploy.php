<?php
// Secure GitHub Auto-Deployment Script
// This script listens for a webhook from GitHub and automatically pulls the latest code.

// Load the deployment secret from .env (to prevent unauthorized triggers)
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) $_ENV[trim($parts[0])] = trim($parts[1], '"\' ');
    }
}

$secret = $_ENV['GITHUB_WEBHOOK_SECRET'] ?? '';

// If no secret is configured, abort for security
if (empty($secret)) {
    http_response_code(500);
    die("Deployment not configured.");
}

// Get the GitHub signature from headers
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (!$signature) {
    http_response_code(403);
    die("Access denied. No signature provided.");
}

// Get the raw POST body
$payload = file_get_contents('php://input');

// Calculate the expected signature
$expected_signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

// Verify the signature securely
if (!hash_equals($expected_signature, $signature)) {
    http_response_code(403);
    die("Access denied. Invalid signature.");
}

// If we reach here, GitHub sent a valid, authenticated webhook
// Execute the git pull command
$output = shell_exec('git pull origin main 2>&1');

// Clear the cache to ensure new code runs perfectly
array_map('unlink', glob(__DIR__ . '/cache/article_*') ?: []);
array_map('unlink', glob(__DIR__ . '/cache/web_*') ?: []);

// Log the deployment for debugging
$log = date('Y-m-d H:i:s') . "\nOutput:\n" . $output . "\n-------------------------\n";
file_put_contents(__DIR__ . '/cache/deploy.log', $log, FILE_APPEND);

echo "Deployed successfully.\n";
echo $output;
?>
