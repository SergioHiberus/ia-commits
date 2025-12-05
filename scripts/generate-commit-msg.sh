#!/bin/bash

# Exit immediately if a command exits with a non-zero status.
set -e

# --- Logging Setup ---
LOG_FILE_PATH="$(dirname "$0")/../${LOG_FILE:-"ia-commits.log"}"
write_log() {
    local LEVEL=$1
    local MESSAGE=$2
    local TIMESTAMP
    TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$TIMESTAMP] [$LEVEL] $MESSAGE" >> "$LOG_FILE_PATH"
}

# --- Check for dependencies ---
if ! command -v jq &> /dev/null
then
    write_log "ERROR" "'jq' command not found. Please install jq to use this script."
    echo "ERROR: 'jq' command not found. Please install jq to use this script." >&2
    exit 1
fi

if ! command -v curl &> /dev/null
then
    write_log "ERROR" "'curl' command not found. Please install curl to use this script."
    echo "ERROR: 'curl' command not found. Please install curl to use this script." >&2
    exit 1
fi

# --- Load Environment ---
# Load .env file if it exists
if [ -f "$(dirname "$0")/../.env" ]; then
    source "$(dirname "$0")/../.env"
fi

# --- Configuration ---
AI_PROVIDER=${AI_PROVIDER:-"gemini"}
COMMIT_MSG_FILE=$1

if [ -z "$COMMIT_MSG_FILE" ]; then
    write_log "ERROR" "Commit message file path not provided."
    echo "ERROR: Commit message file path not provided." >&2
    exit 1
fi

# --- Main Logic ---
write_log "INFO" "Starting commit message generation for $COMMIT_MSG_FILE using provider: $AI_PROVIDER"

# 1. Get the staged git diff
DIFF=$(git diff --staged)

if [ -z "$DIFF" ]; then
    write_log "INFO" "No staged changes detected. Exiting."
    echo "No staged changes detected. Exiting." >&2
    exit 0
fi

# --- Provider-specific functions ---

call_gemini_api() {
    local PROMPT=$1
    
    # Check for API Key
    if [ -z "$GEMINI_API_KEY" ]; then
        write_log "WARNING" "GEMINI_API_KEY not found. Skipping commit message generation."
        echo "WARNING: GEMINI_API_KEY not found. Skipping commit message generation." >&2
        exit 0
    fi
    
    # Configuration
    local API_MODEL=${API_MODEL:-"gemini-2.0-flash"}
    local API_BASE_URL=${API_BASE_URL:-"https://generativelanguage.googleapis.com/v1beta/models/"}
    local API_TIMEOUT_SECONDS=${API_TIMEOUT_SECONDS:-15}
    local MAX_OUTPUT_TOKENS=${MAX_OUTPUT_TOKENS:-300}
    local TEMPERATURE=${TEMPERATURE:-0.2}
    
    # Build JSON payload
    local JSON_PAYLOAD=$(jq -n \
                      --arg prompt "$PROMPT" \
                      --argjson temp "$TEMPERATURE" \
                      --argjson tokens "$MAX_OUTPUT_TOKENS" \
                      '{"contents": [{"parts": [{"text": $prompt}]}],
                        "generationConfig": {
                          "temperature": $temp,
                          "maxOutputTokens": $tokens
                        }}')
    
    local API_URL="${API_BASE_URL}${API_MODEL}:generateContent?key=${GEMINI_API_KEY}"
    
    # Call the Gemini API
    local RESPONSE=$(curl -s -f -X POST "$API_URL" \
         -H "Content-Type: application/json" \
         -d "$JSON_PAYLOAD" --connect-timeout "$API_TIMEOUT_SECONDS")
    
    if [ $? -ne 0 ]; then
        write_log "WARNING" "API call failed. Could not generate commit message."
        echo "WARNING: API call failed. Could not generate commit message." >&2
        exit 0
    fi
    
    # Extract the message
    local GENERATED_MESSAGE=$(echo "$RESPONSE" | jq -r '.candidates[0].content.parts[0].text // empty')
    
    if [ -z "$GENERATED_MESSAGE" ]; then
        write_log "WARNING" "AI did not return a generated message. Full response: $RESPONSE"
        echo "WARNING: AI did not return a generated message." >&2
        exit 0
    fi
    
    echo "$GENERATED_MESSAGE"
}

call_ollama_api() {
    local PROMPT=$1
    
    # Configuration
    local OLLAMA_URL="${OLLAMA_URL:-http://localhost:11434}"
    local OLLAMA_MODEL="${OLLAMA_MODEL:-phi4}"
    local TEMPERATURE=${TEMPERATURE:-0.2}
    
    # Call Ollama API using jq to properly escape JSON
    local RESPONSE=$(jq -n \
        --arg model "$OLLAMA_MODEL" \
        --arg prompt "$PROMPT" \
        --argjson temp "$TEMPERATURE" \
        '{
            model: $model,
            prompt: $prompt,
            stream: false,
            options: {
                temperature: $temp,
                num_predict: 200
            }
        }' | curl -s -X POST "$OLLAMA_URL/api/generate" \
        -H "Content-Type: application/json" \
        -d @-
    )
    
    # Check for errors
    if [ $? -ne 0 ]; then
        write_log "ERROR" "Failed to connect to Ollama API"
        exit 0
    fi
    
    # Extract the response
    local GENERATED_MESSAGE=$(echo "$RESPONSE" | jq -r '.response // empty')
    
    if [ -z "$GENERATED_MESSAGE" ]; then
        write_log "ERROR" "API did not return a generated message. Response: $RESPONSE"
        exit 0
    fi
    
    echo "$GENERATED_MESSAGE"
}

# --- Prepare the prompt ---
MAX_DIFF_LENGTH=${MAX_DIFF_LENGTH:-8000}
TRUNCATED_DIFF=$(echo "$DIFF" | head -c $MAX_DIFF_LENGTH)

PROMPT="Act as an expert developer. Based on the following git 'diff', generate a concise commit message that strictly follows the Conventional Commits standard (e.g., 'feat(scope): description').
Rules:
- Return ONLY the raw commit message, without markdown, code blocks, or any extra formatting.
- The first line (subject) should not exceed 72 characters.
- If the changes are significant, add a blank line followed by a brief message body.

Diff:
$TRUNCATED_DIFF"

# --- Call appropriate provider ---
GENERATED_MESSAGE=""

case "$AI_PROVIDER" in
    gemini)
        GENERATED_MESSAGE=$(call_gemini_api "$PROMPT")
        ;;
    ollama)
        GENERATED_MESSAGE=$(call_ollama_api "$PROMPT")
        ;;
    *)
        write_log "ERROR" "Unknown AI_PROVIDER: $AI_PROVIDER. Valid options are 'gemini' or 'ollama'."
        echo "ERROR: Unknown AI_PROVIDER: $AI_PROVIDER. Valid options are 'gemini' or 'ollama'." >&2
        exit 0
        ;;
esac

# --- Write to commit message file ---
if [ -n "$GENERATED_MESSAGE" ]; then
    # Prepend the new message, followed by a separator and the original content
    ORIGINAL_CONTENT=$(cat "$COMMIT_MSG_FILE" 2>/dev/null || echo "")
    echo -e "$GENERATED_MESSAGE\n\n# ------------------------ > AI Suggestion Above < ------------------------\n$ORIGINAL_CONTENT" > "$COMMIT_MSG_FILE"
    write_log "INFO" "Successfully generated and wrote commit message using $AI_PROVIDER."
else
    write_log "WARNING" "No message was generated."
    echo "WARNING: No message was generated." >&2
fi

exit 0
