#!/bin/bash

# Exit immediately if a command exits with a non-zero status.
set -e

# --- Check for dependencies ---
if ! command -v jq &> /dev/null
then
    echo "ERROR: 'jq' command not found. Please install jq to use this script." >&2
    exit 1
fi

if ! command -v curl &> /dev/null
then
    echo "ERROR: 'curl' command not found. Please install curl to use this script." >&2
    exit 1
fi

# --- Load Environment ---
# Load .env file if it exists
if [ -f "$(dirname "$0")/../.env" ]; then
    source "$(dirname "$0")/../.env"
fi

# Check for API Key
if [ -z "$GEMINI_API_KEY" ]; then
    echo "WARNING: GEMINI_API_KEY not found. Skipping commit message generation." >&2
    exit 0
fi

# --- Configuration ---
API_MODEL=${API_MODEL:-"gemini-2.0-flash"}
API_BASE_URL=${API_BASE_URL:-"https://generativelanguage.googleapis.com/v1beta/models/"}
API_TIMEOUT_SECONDS=${API_TIMEOUT_SECONDS:-15}
MAX_DIFF_LENGTH=${MAX_DIFF_LENGTH:-8000}
MAX_OUTPUT_TOKENS=${MAX_OUTPUT_TOKENS:-300}
TEMPERATURE=${TEMPERATURE:-0.2}

COMMIT_MSG_FILE=$1

if [ -z "$COMMIT_MSG_FILE" ]; then
    echo "ERROR: Commit message file path not provided." >&2
    exit 1
fi

# --- Main Logic ---

# 1. Get the staged git diff
DIFF=$(git diff --staged)

if [ -z "$DIFF" ]; then
    echo "No staged changes detected. Exiting." >&2
    exit 0
fi

# Truncate for safety
TRUNCATED_DIFF=$(echo "$DIFF" | head -c $MAX_DIFF_LENGTH)

# 2. Prepare the prompt and JSON payload
# Using jq to safely build the JSON string
PROMPT="Act as an expert developer. Based on the following git 'diff', generate a concise commit message that strictly follows the Conventional Commits standard (e.g., 'feat(scope): description').
Rules:
- Return ONLY the raw commit message, without markdown, code blocks, or any extra formatting.
- The first line (subject) should not exceed 72 characters.
- If the changes are significant, add a blank line followed by a brief message body.

Diff:
$TRUNCATED_DIFF"

JSON_PAYLOAD=$(jq -n \
                  --arg prompt "$PROMPT" \
                  --argjson temp "$TEMPERATURE" \
                  --argjson tokens "$MAX_OUTPUT_TOKENS" \
                  '{"contents": [{"parts": [{"text": $prompt}]}],
                    "generationConfig": {
                      "temperature": $temp,
                      "maxOutputTokens": $tokens
                    }}')

API_URL="${API_BASE_URL}${API_MODEL}:generateContent?key=${GEMINI_API_KEY}"

# 3. Call the Gemini API
RESPONSE=$(curl -s -f -X POST "$API_URL" \
     -H "Content-Type: application/json" \
     -d "$JSON_PAYLOAD" --connect-timeout "$API_TIMEOUT_SECONDS")

if [ $? -ne 0 ]; then
    echo "WARNING: API call failed. Could not generate commit message." >&2
    exit 0
fi

# 4. Extract the message and write to file
GENERATED_MESSAGE=$(echo "$RESPONSE" | jq -r '.candidates[0].content.parts[0].text // empty')

if [ -n "$GENERATED_MESSAGE" ]; then
    # Prepend the new message, followed by a separator and the original content
    ORIGINAL_CONTENT=$(cat "$COMMIT_MSG_FILE" 2>/dev/null || echo "")
    echo -e "$GENERATED_MESSAGE\n\n# ------------------------ > AI Suggestion Above < ------------------------\n$ORIGINAL_CONTENT" > "$COMMIT_MSG_FILE"
else
    echo "WARNING: AI did not return a generated message." >&2
fi

exit 0
