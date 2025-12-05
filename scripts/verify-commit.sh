#!/bin/bash

# Exit immediately if a command exits with a non-zero status.
# set -e # We need to handle errors manually to show custom messages.

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
    exit 0 # Don't block commit if setup is wrong
fi

if ! command -v curl &> /dev/null
then
    write_log "ERROR" "'curl' command not found. Please install curl to use this script."
    echo "ERROR: 'curl' command not found. Please install curl to use this script." >&2
    exit 0 # Don't block commit if setup is wrong
fi

# --- Load Environment ---
if [ -f "$(dirname "$0")/../.env" ]; then
    source "$(dirname "$0")/../.env"
fi

# --- Configuration ---
AI_PROVIDER=${AI_PROVIDER:-"gemini"}
COMMIT_MSG_FILE=$1

if [ -z "$COMMIT_MSG_FILE" ] || [ ! -f "$COMMIT_MSG_FILE" ]; then
    write_log "WARNING" "Could not find commit message file. Verification skipped."
    echo "WARNING: Could not find commit message file. Verification skipped." >&2
    exit 0
fi

# --- Main Logic ---
write_log "INFO" "Starting commit message verification for $COMMIT_MSG_FILE using provider: $AI_PROVIDER"

# 1. Get the commit message
MESSAGE=$(cat "$COMMIT_MSG_FILE" | grep -v '^#')

if [ -z "$MESSAGE" ]; then
    write_log "INFO" "Empty commit message. Verification skipped."
    echo "Empty commit message. Verification skipped." >&2
    exit 0
fi

# --- Provider-specific functions ---

call_gemini_verify() {
    local PROMPT=$1
    
    # Check for API Key
    if [ -z "$GEMINI_API_KEY" ]; then
        write_log "WARNING" "GEMINI_API_KEY not found. AI verification will be skipped."
        echo "WARNING: GEMINI_API_KEY not found. AI verification will be skipped." >&2
        exit 0
    fi
    
    # Configuration
    local API_MODEL=${API_MODEL:-"gemini-2.0-flash"}
    local API_BASE_URL=${API_BASE_URL:-"https://generativelanguage.googleapis.com/v1beta/models/"}
    local API_TIMEOUT_SECONDS=${API_TIMEOUT_SECONDS:-15}
    local TEMPERATURE_VERIFY=${TEMPERATURE_VERIFY:-0.1}
    
    # Build JSON payload
    local JSON_PAYLOAD=$(jq -n \
                      --arg prompt "$PROMPT" \
                      --argjson temp "$TEMPERATURE_VERIFY" \
                      '{"contents": [{"parts": [{"text": $prompt}]}],
                        "generationConfig": {
                          "temperature": $temp,
                          "responseMimeType": "application/json"
                        }}')
    
    local API_URL="${API_BASE_URL}${API_MODEL}:generateContent?key=${GEMINI_API_KEY}"
    
    # Call the Gemini API
    local RESPONSE=$(curl -s -f -X POST "$API_URL" \
         -H "Content-Type: application/json" \
         -d "$JSON_PAYLOAD" --connect-timeout "$API_TIMEOUT_SECONDS")
    
    if [ $? -ne 0 ]; then
        write_log "WARNING" "AI API call failed. Verification skipped."
        echo "WARNING: AI API call failed. Verification skipped." >&2
        exit 0
    fi
    
    # Extract the validation result
    local IS_VALID=$(echo "$RESPONSE" | jq -r '.candidates[0].content.parts[0].text | fromjson | .valid // false')
    local REASON=$(echo "$RESPONSE" | jq -r '.candidates[0].content.parts[0].text | fromjson | .reason // "Unknown reason."')
    
    echo "$IS_VALID|$REASON"
}

call_ollama_verify() {
    local PROMPT=$1
    
    # Configuration
    local OLLAMA_URL="${OLLAMA_URL:-http://localhost:11434}"
    local OLLAMA_MODEL="${OLLAMA_MODEL:-phi4}"
    
    # Call Ollama API using jq to properly escape JSON
    local RESPONSE=$(jq -n \
        --arg model "$OLLAMA_MODEL" \
        --arg prompt "$PROMPT" \
        '{
            model: $model,
            prompt: $prompt,
            stream: false,
            format: "json",
            options: {
                temperature: 0.1
            }
        }' | curl -s -X POST "$OLLAMA_URL/api/generate" \
        -H "Content-Type: application/json" \
        -d @-
    )
    
    # Check for errors
    if [ $? -ne 0 ]; then
        echo "⚠️ WARNING: Failed to connect to Ollama API. Verification skipped."
        exit 0
    fi
    
    # Extract the response
    local RESULT=$(echo "$RESPONSE" | jq -r '.response // "{}"')
    
    # Clean markdown code blocks if AI adds them
    RESULT=$(echo "$RESULT" | sed 's/^```json//g' | sed 's/```$//g')
    
    # Parse the validation result
    local IS_VALID=$(echo "$RESULT" | jq -r '.valid // true')
    local REASON=$(echo "$RESULT" | jq -r '.reason // "The format is incorrect."')
    
    echo "$IS_VALID|$REASON"
}

# --- Prepare the prompt ---
PROMPT="Evaluate if the following commit message strictly follows the Conventional Commits standard (type(scope): description). 
Valid types are: feat, fix, docs, style, refactor, perf, test, build, ci, chore. 
Respond ONLY with a JSON object. 
If valid, 'valid' is true and 'reason' is null. 
If invalid, 'valid' is false and 'reason' explains the error concisely in English.
Message to evaluate: '$MESSAGE'"

# --- Call appropriate provider ---
VALIDATION_RESULT=""

case "$AI_PROVIDER" in
    gemini)
        VALIDATION_RESULT=$(call_gemini_verify "$PROMPT")
        ;;
    ollama)
        VALIDATION_RESULT=$(call_ollama_verify "$PROMPT")
        ;;
    *)
        write_log "ERROR" "Unknown AI_PROVIDER: $AI_PROVIDER. Valid options are 'gemini' or 'ollama'."
        echo "ERROR: Unknown AI_PROVIDER: $AI_PROVIDER. Valid options are 'gemini' or 'ollama'." >&2
        exit 0
        ;;
esac

# Parse the result
IS_VALID=$(echo "$VALIDATION_RESULT" | cut -d'|' -f1)
REASON=$(echo "$VALIDATION_RESULT" | cut -d'|' -f2-)

if [ "$IS_VALID" != "true" ]; then
    write_log "ERROR" "AI VALIDATION FAILED: Commit message does not meet the standard. Reason: $REASON"
    echo "------------------------------------------------------------------" >&2
    echo "🚨 AI VALIDATION FAILED: The commit message does not meet the standard." >&2
    echo "AI Reason: $REASON" >&2
    echo "------------------------------------------------------------------" >&2
    exit 1
fi

write_log "INFO" "Commit message validated successfully by AI using $AI_PROVIDER."
exit 0
