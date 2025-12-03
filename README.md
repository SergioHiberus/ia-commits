# IA Commits

AI-powered Git hooks for automatic commit message generation and validation using Google Gemini API. This project helps maintain a clean and consistent Git history by enforcing the Conventional Commits standard.

## Description

This project provides intelligent Git hooks that leverage Google's Gemini AI to:
- **Auto-generate** commit messages following the Conventional Commits standard based on your staged changes (`git diff --staged`).
- **Validate** commit messages to ensure they comply with the Conventional Commits specification before a commit is finalized.

The hooks integrate seamlessly with your Git workflow, enhancing productivity while maintaining code quality standards.

## Features

- **AI-Powered Generation**: Automatically generates meaningful commit messages from git diffs.
- **Smart Validation**: Validates commit messages against the Conventional Commits format, preventing non-compliant commits.
- **Cross-Platform (via Git Bash)**: Designed to work on Linux, macOS, and Windows (using Git Bash).
- **Non-Intrusive**: Only runs when appropriate (skips merges, squashes, and direct `-m` commits for generation).
- **Fail-Closed Validation**: If AI validation encounters an error or ambiguity, the commit is rejected by default, ensuring strict compliance.
- **Conventional Commits**: Enforces industry-standard commit message format.
- **Logging**: Detailed execution logs are maintained for troubleshooting.

## Requirements

- **Bash** (natively on Linux/macOS, via Git Bash on Windows)
- **`curl`** (command-line tool for transferring data with URLs)
- **`jq`** (command-line JSON processor)
- **Git**
- **Husky** (for managing Git hooks - installed via npm)
- **Google Gemini API Key** ([Get one here](https://aistudio.google.com/app/apikey))

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/SergioHiberus/ia-commits.git
cd ia-commits
```

### 2. Install Dependencies

You need `curl` and `jq` installed on your system.

**Linux (Debian/Ubuntu):**
```bash
sudo apt-get update && sudo apt-get install -y curl jq
```

**macOS (Homebrew):**
```bash
brew install curl jq
```

**Windows (using Git Bash):**
If you have Git Bash, `curl` is usually included. For `jq`:
1. Download the `jq` executable for Windows from its official GitHub releases page (e.g., `jq-win64.exe`).
2. Rename it to `jq.exe` and place it in a directory that's in your Git Bash `PATH` (e.g., `C:\Program Files\Git\usr\bin`).

Alternatively, within Git Bash, you might be able to use `pacman` (if available):
```bash
pacman -Sy jq
```
Or with Chocolatey (if installed):
```bash
choco install jq
```

### 3. Install Node.js dependencies (for Husky)

```bash
npm install
```

### 4. Configure environment variables

Create a `.env` file in the project root. If you don't have one, copy the example:

```bash
cp .env.example .env # (If you have .env.example)
```

Edit `.env` and add your Gemini API key. You can also customize other AI parameters:

```env
# Mandatory: Your Google Gemini API Key
GEMINI_API_KEY="your_api_key_here"

# --- Optional AI Configuration ---
API_MODEL="gemini-1.5-flash"                  # AI model to use (e.g., gemini-1.5-flash, gemini-pro)
API_BASE_URL="https://generativelanguage.googleapis.com/v1beta/models/" # Gemini API base URL
API_TIMEOUT_SECONDS=15                        # Timeout for API calls in seconds
MAX_DIFF_LENGTH=8000                          # Max characters of git diff to send to AI
MAX_OUTPUT_TOKENS=300                         # Max tokens for AI-generated messages
TEMPERATURE=0.2                               # AI generation temperature (0.0-1.0)
TEMPERATURE_VERIFY=0.1                        # AI verification temperature (usually lower for strictness)
LOG_FILE="ia-commits.log"                     # Log file path relative to project root
```

### 5. Initialize Husky hooks

```bash
npm run prepare
```

This configures Git to use the hooks in `.husky/` directory, which now point to the Bash scripts.

## Usage

### Auto-generating Commit Messages

When you make a commit **without** the `-m` flag, the AI will automatically generate a commit message based on your staged changes:

```bash
git add .
git commit
# Your editor will open with an AI-generated message pre-filled.
# The AI suggestion will be prefixed with: # ------------------------ > AI Suggestion Above < ------------------------
# You can edit the message or keep the AI's suggestion.
```

**Important**: The hook for generation only runs when you use `git commit` without `-m` (or other options that pre-fill the message). If you use `git commit -m "message"`, the generation hook is skipped (standard Git behavior).

### Commit Message Validation

Every commit message is automatically validated against the Conventional Commits standard. If the message doesn't comply, the commit will be rejected with a helpful error message to your console.

**Valid commit types:**
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, etc.)
- `refactor`: Code refactoring
- `perf`: Performance improvements
- `test`: Adding or updating tests
- `build`: Build system changes
- `ci`: CI/CD changes
- `chore`: Other changes (maintenance, etc.)

**Example valid messages:**
```
feat(auth): add user login functionality
fix(api): resolve null pointer exception in user service
docs: update installation instructions
```

## Logging

All script executions, warnings, and errors are logged to `ia-commits.log` in the project root. This file is automatically ignored by Git.

```bash
cat ia-commits.log
```

## Project Structure

```
ia-commits/
├── .husky/                      # Git hooks directory (commit-msg, prepare-commit-msg)
├── scripts/                     # Bash scripts
│   ├── generate-commit-msg.sh   # AI message generation
│   └── verify-commit.sh         # Message validation
├── .env                         # Environment variables (not tracked by Git)
├── ia-commits.log               # Script execution logs (not tracked by Git)
├── package.json                 # Node.js dependencies (for Husky)
└── README.md                    # This file
```

## Troubleshooting

### Hooks not executing

1. Ensure Husky is configured:
   ```bash
   npm run prepare
   git config core.hooksPath
   # Should output: .husky/
   ```
   (Note: Husky 7+ changed the hooksPath from `.husky/_` to `.husky/`)

2. Verify hook permissions:
   ```bash
   chmod +x .husky/commit-msg
   chmod +x .husky/prepare-commit-msg
   chmod +x scripts/generate-commit-msg.sh
   chmod +x scripts/verify-commit.sh
   ```

### `jq` or `curl` command not found

Install `jq` and `curl` as per the "Installation" section for your operating system.

### API Key not loading

1. Verify `.env` file exists in the project root and contains `GEMINI_API_KEY`.
2. Ensure the key is not empty and is correctly formatted.

### Empty commit messages / AI not responding

1. Check that you're using `git commit` without `-m`.
2. Ensure there are staged changes: `git status`.
3. Review `ia-commits.log` for any API errors or warnings.
4. Verify your `GEMINI_API_KEY` is valid and has access to the Gemini API.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

ISC

## Author

SergioHiberus

## Acknowledgments

- Powered by [Google Gemini API](https://ai.google.dev/)
- Built with [Husky](https://typicode.github.io/husky/)
- Follows [Conventional Commits](https://www.conventionalcommits.org/)