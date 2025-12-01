<?php
// scripts/generate-commit-msg.php

require __DIR__ . '/../vendor/autoload.php';

// --- LOG CONFIGURATION ---
$logFile = __DIR__ . '/../ia-commits.log';

function log_error($message)
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// --- ENVIRONMENT LOADING ---
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use GuzzleHttp\Client;

// --- API CONFIGURATION ---
// Try to get the key from $_ENV, $_SERVER or getenv()
$apiKey = $_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY');

// Use gemini-2.0-flash which is available in v1beta
$model = 'gemini-2.0-flash';
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$apiKey";

if (!$apiKey) {
    log_error("Debug: __DIR__ is " . __DIR__);
    log_error("Debug: Expected .env at " . realpath(__DIR__ . '/..') . "/.env");
    log_error("Debug: File exists? " . (file_exists(__DIR__ . '/../.env') ? 'YES' : 'NO'));
    log_error("Debug: Env vars keys: " . implode(', ', array_keys($_ENV)));
    log_error('Error: GEMINI_API_KEY not found in .env file or not configured.');
    exit(0);
}

// 1. Get the changes (diff)
$diff = shell_exec('git diff --staged');

if (empty(trim($diff))) {
    exit(0); // No changes, exit.
}

// 2. Technical prompt for AI
$prompt = "Act as an expert developer. Based on the following git 'diff', generate a concise commit message that strictly follows the Conventional Commits standard (type(scope): description).
Rules:
- Only return the commit message.
- Do not use markdown or code blocks.
- Maximum 100 characters for the first line.
- If there are important changes, use a brief message body.

Diff:
" . substr($diff, 0, 8000); // Truncate for safety

$client = new Client();
$commitMsgFile = $argv[1] ?? null;

try {
    // Call to Google Gemini API
    $response = $client->post($apiUrl, [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 200,
            ]
        ],
        'timeout' => 10
    ]);

    $body = json_decode($response->getBody(), true);

    if (isset($body['error'])) {
        $errorMessage = $body['error']['message'] ?? 'Unknown API error.';
        log_error("Gemini API error: " . $errorMessage);
        exit(0);
    }

    $generatedMessage = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if ($generatedMessage && $commitMsgFile) {
        $generatedMessage = trim($generatedMessage);
        $originalContent = file_exists($commitMsgFile) ? file_get_contents($commitMsgFile) : '';
        file_put_contents($commitMsgFile, $generatedMessage . "\n\n# ---------------------------------------------------\n" . $originalContent);
    } elseif (!$generatedMessage) {
        log_error("API did not return a generated message. Response received: " . json_encode($body));
    }

} catch (\Exception $e) {
    log_error("Exception caught: " . $e->getMessage());
}

exit(0);
