<?php
// scripts/verify-commit.php

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

// Load environment variables for this script
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// --- Configuration ---
define('LOG_FILE', __DIR__ . '/../' . ($_ENV['LOG_FILE'] ?? 'ia-commits.log'));
define('API_MODEL', $_ENV['API_MODEL'] ?? 'gemini-2.0-flash');
define('API_BASE_URL', $_ENV['API_BASE_URL'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/');
define('API_TIMEOUT_SECONDS', (int)($_ENV['API_TIMEOUT_SECONDS'] ?? 15));
define('TEMPERATURE_VERIFY', (float)($_ENV['TEMPERATURE_VERIFY'] ?? 0.1));

/**
 * Logs a message to the configured log file.
 */
function write_log(string $level, string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
}

/**
 * Loads the Gemini API key from environment variables.
 * Returns null if the key is not found.
 */
function load_api_key(): ?string
{
    return $_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY');
}

/**
 * Retrieves the commit message from the specified file.
 * Returns null if the file doesn't exist or is empty.
 */
function get_commit_message(string $commitMsgFile): ?string
{
    if (!file_exists($commitMsgFile)) {
        write_log('WARNING', 'Could not find commit message file. Verification skipped.');
        echo "WARNING: Could not find commit message file. Verification skipped.\n";
        return null;
    }

    $message = trim(file_get_contents($commitMsgFile));

    if (empty($message)) {
        write_log('INFO', 'Empty commit message. Verification skipped.');
        return null;
    }

    return $message;
}

/**
 * Verifies the commit message format using the Gemini API.
 * Returns a result array from the API or null on failure.
 */
function verify_commit_message_with_ai(string $message, string $apiKey): ?array
{
    $prompt = "Evaluate if the following commit message strictly follows the Conventional Commits standard (type(scope): description). 
Valid types are: feat, fix, docs, style, refactor, perf, test, build, ci, chore. 
Respond ONLY with a JSON object. 
If valid, 'valid' is true and 'reason' is null. 
If invalid, 'valid' is false and 'reason' explains the error concisely in English.
Message to evaluate: '$message'";

    $apiUrl = API_BASE_URL . API_MODEL . ':generateContent?key=' . $apiKey;
    $client = new Client();

    try {
        $response = $client->post($apiUrl, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => TEMPERATURE_VERIFY,
                    'responseMimeType' => 'application/json'
                ]
            ],
            'timeout' => API_TIMEOUT_SECONDS
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
        $text = preg_replace('/^```json\s*|\s*```$/', '', $text); // Clean Markdown

        return json_decode($text, true);

    } catch (GuzzleException $e) {
        write_log('WARNING', "Failed to connect to AI API. Verification skipped. Error: " . $e->getMessage());
        echo "WARNING: Failed to connect to AI API. Verification skipped.\n";
        return null;
    }
}

/**
 * --- Main execution block ---
 */
function main(array $argv): int
{
    $apiKey = load_api_key();
    if (!$apiKey) {
        write_log('ERROR', 'GEMINI_API_KEY environment variable is not configured. AI verification skipped.');
        echo "️ERROR: GEMINI_API_KEY environment variable is not configured. AI verification skipped.\n";
        return 0; // Don't block commit if key is missing
    }

    $commitMsgFile = $argv[1] ?? null;
    if (!$commitMsgFile) {
        return 0; // Should not happen in git hook context, but safe to exit
    }

    $message = get_commit_message($commitMsgFile);
    if (!$message) {
        return 0; // No message to verify
    }

    try {
        $result = verify_commit_message_with_ai($message, $apiKey);

        if ($result === null) {
            return 0; // API call failed or returned invalid data, don't block commit
        }

        if (isset($result['valid']) && $result['valid'] === false) {
            $reason = $result['reason'] ?? 'The format is incorrect.';
            write_log('ERROR', "AI VALIDATION FAILED: The commit message does not meet the standard. Reason: " . $reason);
            echo "AI VALIDATION FAILED: The commit message does NOT meet the standard.\n";
            echo "AI Reason: $reason\n";
            return 1; // Block commit
        }
        
        write_log('INFO', "Commit message validated successfully by AI.");

    } catch (Exception $e) {
        write_log('WARNING', "Unexpected error during AI verification. Verification skipped. Error: " . $e->getMessage());
        echo "WARNING: Unexpected error in AI verification. Verification skipped.\n";
        return 0; // Don't block commit on unexpected errors
    }

    return 0; // Success
}

// --- Run Application ---
exit(main($argv));