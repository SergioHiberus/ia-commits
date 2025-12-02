<?php
// scripts/generate-commit-msg.php

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables as early as possible
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// --- Configuration ---
// The `load_api_key` function already ensures dotenv is loaded, but this makes configuration
// available earlier and for other functions that might not call `load_api_key`.

define('LOG_FILE', __DIR__ . '/../' . ($_ENV['LOG_FILE'] ?? 'ia-commits.log'));
define('API_MODEL', $_ENV['API_MODEL'] ?? 'gemini-2.0-flash'); // Default to a reasonable model
define('API_BASE_URL', $_ENV['API_BASE_URL'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/');
define('API_TIMEOUT_SECONDS', (int)($_ENV['API_TIMEOUT_SECONDS'] ?? 15));
define('MAX_DIFF_LENGTH', (int)($_ENV['MAX_DIFF_LENGTH'] ?? 8000));
define('MAX_OUTPUT_TOKENS', (int)($_ENV['MAX_OUTPUT_TOKENS'] ?? 300));
define('TEMPERATURE', (float)($_ENV['TEMPERATURE'] ?? 0.2));

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

/**
 * Loads the Gemini API key from environment variables.
 * Exits with an error if the key is not found.
 *
 * @return string The API key.
 */
function load_api_key(): string
{
    $apiKey = $_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY');

    if (!$apiKey) {
        write_log('ERROR', 'GEMINI_API_KEY not found. Ensure it is set in your .env file or as an environment variable.');
        exit(1); // Exit with an error code for critical failure.
    }

    return $apiKey;
}

/**
 * Gets the staged git diff.
 *
 * @return string The git diff, or an empty string if there are no staged changes.
 */
function get_staged_diff(): string
{
    $diff = shell_exec('git diff --staged');
    return $diff ? trim($diff) : '';
}

/**
 * Generates a commit message using the Gemini API.
 *
 * @param string $diff The git diff to use for generating the message.
 * @param string $apiKey The API key.
 * @return string|null The generated commit message, or null on failure.
 */
function generate_commit_message(string $diff, string $apiKey): ?string
{
    $prompt = "Act as an expert developer. Based on the following git 'diff', generate a concise commit message that strictly follows the Conventional Commits standard (e.g., 'feat(scope): description').
Rules:
- Return ONLY the raw commit message, without markdown, code blocks, or any extra formatting.
- The first line (subject) should not exceed 72 characters.
- If the changes are significant, add a blank line followed by a brief message body.

Diff:
" . substr($diff, 0, MAX_DIFF_LENGTH);

    $apiUrl = API_BASE_URL . API_MODEL . ':generateContent?key=' . $apiKey;
    $client = new \GuzzleHttp\Client();

    try {
        $response = $client->post($apiUrl, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => TEMPERATURE,
                    'maxOutputTokens' => MAX_OUTPUT_TOKENS,
                ]
            ],
            'timeout' => API_TIMEOUT_SECONDS
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            write_log('ERROR', 'Failed to decode API response JSON. Error: ' . json_last_error_msg());
            return null;
        }

        if (isset($body['error'])) {
            $errorMessage = $body['error']['message'] ?? 'Unknown API error.';
            write_log('ERROR', "Gemini API error: " . $errorMessage);
            return null;
        }

        return $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

    } catch (\GuzzleHttp\Exception\GuzzleException $e) {
        write_log('ERROR', "API call failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Writes the generated message to the commit message file.
 *
 * @param string $commitMsgFile The path to the commit message file.
 * @param string $generatedMessage The message to write.
 */
function write_commit_message(string $commitMsgFile, string $generatedMessage): void
{
    $trimmedMessage = trim($generatedMessage);
    $originalContent = file_exists($commitMsgFile) ? file_get_contents($commitMsgFile) : '';
    // Prepend the new message, followed by a separator and the original content (e.g., comments from Git).
    $newContent = $trimmedMessage . "\n\n# ------------------------ > AI Suggestion Above < ------------------------\n" . $originalContent;

    if (file_put_contents($commitMsgFile, $newContent) === false) {
        write_log('ERROR', "Failed to write to commit message file: $commitMsgFile");
        exit(1); // Critical error, something is wrong with file permissions or path.
    }
}

/**
 * --- Main execution block ---
 */
function main(array $argv): int
{
    // The commit message file path is passed as the first argument by the git hook.
    $commitMsgFile = $argv[1] ?? null;
    if (!$commitMsgFile) {
        // This can happen if the script is run directly without arguments. Not a critical error.
        write_log('INFO', 'Commit message file path not provided. Exiting.');
        return 0;
    }

    try {
        $apiKey = load_api_key();
        $diff = get_staged_diff();

        if (empty($diff)) {
            // No staged changes, so no message is needed.
            write_log('INFO', 'No staged changes detected. Exiting.');
            return 0;
        }

        $generatedMessage = generate_commit_message($diff, $apiKey);

        if ($generatedMessage) {
            write_commit_message($commitMsgFile, $generatedMessage);
            write_log('INFO', "Successfully generated and wrote commit message to $commitMsgFile.");
        } else {
            // Don't block the commit if the API fails. The user can still write a message manually.
            write_log('ERROR', 'Failed to generate a commit message. Proceeding with empty suggestion.');
        }

    } catch (Exception $e) {
        // Catch any unexpected errors to prevent the commit from being blocked.
        write_log('ERROR', 'An unexpected error occurred: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
        return 0; // Non-zero would block commit.
    }

    return 0; // Success
}

// --- Run Application ---
exit(main($argv));
