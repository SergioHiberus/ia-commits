#!/bin/bash

# Exit immediately if a command exits with a non-zero status.
# set -e # We need to handle errors manually to show custom messages.

# --- Check for dependencies ---
if ! command -v jq &> /dev/null
then
    echo "ERROR: 'jq' command not found. Please install jq to use this script." >&2
    exit 0 # Don't block commit if setup is wrong
fi

if ! command -v curl &> /dev/null
then
    echo "ERROR: 'curl' command not found. Please install curl to use this script." >&2
    exit 0 # Don't block commit if setup is wrong
fi

# --- Load Environment ---
if [ -f "$(dirname "$0")/../.env" ]; then
    source "$(dirname "$0")/../.env"
fi

# Check for API Key
if [ -z "$GEMINI_API_KEY" ]; then
    echo "WARNING: GEMINI_API_KEY not found. AI verification will be skipped." >&2
    exit 0
fi

# --- Configuration ---
API_MODEL=${API_MODEL:-"gemini-2.0-flash"}
API_BASE_URL=${API_BASE_URL:-"https://generativelanguage.googleapis.com/v1beta/models/"}
API_TIMEOUT_SECONDS=${API_TIMEOUT_SECONDS:-15}
TEMPERATURE_VERIFY=${TEMPERATURE_VERIFY:-0.1}

COMMIT_MSG_FILE=$1

if [ -z "$COMMIT_MSG_FILE" ] || [ ! -f "$COMMIT_MSG_FILE" ]; then
    echo "WARNING: Could not find commit message file. Verification skipped." >&2
    exit 0
fi

# --- Main Logic ---

# 1. Get the commit message
MESSAGE=$(cat "$COMMIT_MSG_FILE" | grep -v '^#')

if [ -z "$MESSAGE" ]; then
    echo "Empty commit message. Verification skipped." >&2
    exit 0
fi

# 2. Prepare the prompt and JSON payload
PROMPT="Evaluate if the following commit message strictly follows the Conventional Commits standard (type(scope): description). 
Valid types are: feat, fix, docs, style, refactor, perf, test, build, ci, chore. 
Respond ONLY with a JSON object. 
If valid, 'valid' is true and 'reason' is null. 
If invalid, 'valid' is false and 'reason' explains the error concisely in English.
Message to evaluate: '$MESSAGE'"

JSON_PAYLOAD=$(jq -n \
                  --arg prompt "$PROMPT" \
                  --argjson temp "$TEMPERATURE_VERIFY" \
                  '{"contents": [{"parts": [{"text": $prompt}]}],
                    "generationConfig": {
                      "temperature": $temp,
                      "responseMimeType": "application/json"
                    }}')

API_URL="${API_BASE_URL}${API_MODEL}:generateContent?key=${GEMINI_API_KEY}"

# 3. Call the Gemini API
RESPONSE=$(curl -s -f -X POST "$API_URL" \
     -H "Content-Type: application/json" \
     -d "$JSON_PAYLOAD" --connect-timeout "$API_TIMEOUT_SECONDS")

if [ $? -ne 0 ]; then
    echo "WARNING: AI API call failed. Verification skipped." >&2
    exit 0
fi

# 4. Extract the validation result
IS_VALID=$(echo "$RESPONSE" | jq -r '.candidates[0].content.parts[0].text | fromjson | .valid // false')
REASON=$(echo "$RESPONSE" | jq -r '.candidates[0].content.parts[0].text | fromjson | .reason // "Unknown reason."')

if [ "$IS_VALID" != "true" ]; then
    echo "------------------------------------------------------------------" >&2
    echo "AI VALIDATION FAILED: The commit message does not meet the standard." >&2
    echo "AI Reason: $REASON" >&2
    echo "------------------------------------------------------------------" >&2
    exit 1
fi

exit 0
