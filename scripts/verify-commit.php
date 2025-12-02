<?php
// scripts/verify-commit.php

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables for this script
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// --- Configuration ---
define('LOG_FILE', __DIR__ . '/../' . ($_ENV['LOG_FILE'] ?? 'ia-commits.log'));
define('API_MODEL', $_ENV['API_MODEL'] ?? 'gemini-2.0-flash'); // Consistent with generate-commit-msg.php
define('API_BASE_URL', $_ENV['API_BASE_URL'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/');
define('API_TIMEOUT_SECONDS', (int)($_ENV['API_TIMEOUT_SECONDS'] ?? 15)); // Consistent with generate-commit-msg.php
define('TEMPERATURE_VERIFY', (float)($_ENV['TEMPERATURE_VERIFY'] ?? 0.1)); // Specific temperature for verification

/**
 * Logs a message to the configured log file.
 *
 * @param string $level The log level (e.g., 'ERROR', 'INFO').
 * @param string $message The message to log.
 */
function write_log(string $level, string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
}

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// --- API CONFIGURATION ---
$apiKey = $_ENV['GEMINI_API_KEY'] ?? null;
$apiUrl = API_BASE_URL . API_MODEL . ':generateContent?key=' . $apiKey;
// ---------------------

if (!$apiKey) {
    write_log('ERROR', 'GEMINI_API_KEY environment variable is not configured. AI verification skipped.');
    echo "️ERROR: GEMINI_API_KEY environment variable is not configured. AI verification skipped.\n";
    exit(0);
}

// 1. Get the commit message
$commitMsgFile = $argv[1] ?? null;
if (!$commitMsgFile || !file_exists($commitMsgFile)) {
    write_log('WARNING', 'Could not find commit message file. Verification skipped.');
    echo "WARNING: Could not find commit message file. Verification skipped.\n";
    exit(0);
}

$message = trim(file_get_contents($commitMsgFile));

if (empty($message)) {
    // Allow empty commit if the flow permits it
    write_log('INFO', 'Empty commit message. Verification skipped.');
    exit(0);
}

// 2. Technical prompt for AI
$prompt = "Evaluate if the following commit message strictly follows the Conventional Commits standard (type(scope): description). 
Valid types are: feat, fix, docs, style, refactor, perf, test, build, ci, chore. 
Respond ONLY with a JSON object. 
If valid, 'valid' is true and 'reason' is null. 
If invalid, 'valid' is false and 'reason' explains the error concisely in English.
Message to evaluate: '$message'";

$client = new Client();

try {
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
                'temperature' => TEMPERATURE_VERIFY,
                'responseMimeType' => 'application/json'
            ]
        ],
        'timeout' => API_TIMEOUT_SECONDS
    ]);

    $body = json_decode($response->getBody()->getContents(), true); // Use getContents()
    $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '{}';

    // Clean Markdown code blocks if AI adds them
    $text = preg_replace('/^```json\s*|\s*```$/', '', $text);

    $result = json_decode($text, true);

    if (isset($result['valid']) && $result['valid'] === false) {
        $reason = $result['reason'] ?? 'The format is incorrect.';
        write_log('ERROR', "AI VALIDATION ERROR: The commit message does NOT meet the standard. Reason: " . $reason);
        echo "AI VALIDATION ERROR: The commit message does NOT meet the standard.\n";
        echo "AI reason: $reason\n";
        exit(1);
    }

} catch (RequestException $e) {
    write_log('WARNING', "Failed to connect to AI API. Verification skipped. Error: " . $e->getMessage());
    echo "WARNING: Failed to connect to AI API. Verification skipped.\n";
    exit(0);
} catch (\Exception $e) {
    write_log('WARNING', "Unexpected error in AI verification. Verification skipped. Error: " . $e->getMessage());
    echo "WARNING: Unexpected error in AI verification. Verification skipped.\n";
    exit(0);
}

exit(0);