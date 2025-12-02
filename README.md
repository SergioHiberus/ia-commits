# IA Commits

AI-powered Git hooks for automatic commit message generation and validation using Google Gemini API.

## Description

This project provides intelligent Git hooks that leverage Google's Gemini AI to:
- **Auto-generate** commit messages following Conventional Commits standard based on your staged changes
- **Validate** commit messages to ensure they comply with best practices

The hooks integrate seamlessly with your Git workflow, enhancing productivity while maintaining code quality standards.

## Features

- **AI-Powered Generation**: Automatically generates meaningful commit messages from git diffs
- **Smart Validation**: Validates commit messages against Conventional Commits format
- **Non-Intrusive**: Only runs when appropriate (skips merges, squashes, and `-m` commits)
- **Fail-Safe**: Gracefully handles API errors without blocking your workflow
- **Conventional Commits**: Enforces industry-standard commit message format

## Requirements

- **PHP** >= 8.1
- **Composer** (for PHP dependencies)
- **Node.js** >= 16 (for Husky)
- **npm** (for package management)
- **Git**
- **Google Gemini API Key** ([Get one here](https://aistudio.google.com/app/apikey))

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/SergioHiberus/ia-commits.git
cd ia-commits
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Install Node.js dependencies

```bash
npm install
```

### 4. Configure environment variables

Create a `.env` file in the project root:

```bash
cp .env.example .env
```

Edit `.env` and add your Gemini API key:

```env
GEMINI_API_KEY=your_api_key_here
```

### 5. Initialize Husky hooks

```bash
npm run prepare
```

This configures Git to use the hooks in `.husky/` directory.

## Usage

### Auto-generating Commit Messages

When you make a commit **without** the `-m` flag, the AI will automatically generate a commit message based on your staged changes:

```bash
git add .
git commit
# Your editor will open with an AI-generated message pre-filled
```

**Important**: The hook only runs when you use `git commit` without `-m`. If you use `git commit -m "message"`, the hook is skipped (standard Git behavior).

### Commit Message Validation

Every commit message is automatically validated against the Conventional Commits standard. If the message doesn't comply, the commit will be rejected with a helpful error message.

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

## Project Structure

```
ia-commits/
├── .husky/                      # Git hooks directory
│   ├── commit-msg               # Validates commit messages
│   └── prepare-commit-msg       # Generates commit messages
├── scripts/                     # PHP scripts
│   ├── generate-commit-msg.php  # AI message generation
│   └── verify-commit.php        # Message validation
├── .env                         # Environment variables (not in repo)
├── ia-commits.log               # Commits log (not in repo)
├── composer.json                # PHP dependencies
├── package.json                 # Node.js dependencies
├── GEMINI.md                    # Gemini instructions
└── README.md                    # This file
```

## Configuration

### Customizing the AI Prompt

Edit `scripts/generate-commit-msg.php` to modify the prompt sent to Gemini AI:

```php
$prompt = "Your custom prompt here...";
```

### Changing the AI Model

By default, the project uses `gemini-2.0-flash`. To use a different model, update both scripts:

```php
$model = 'gemini-2.0-flash'; // Change this
```

### Debug Mode

To enable debug logging, check the logs:

```bash
# Hook execution logs
cat /tmp/hook-debug.log

# API interaction logs
cat ia-commits.log
```

## Troubleshooting

### Hooks not executing

1. Ensure Husky is configured:
   ```bash
   npm run prepare
   git config core.hooksPath
   # Should output: .husky/_
   ```

2. Verify hook permissions:
   ```bash
   chmod +x .husky/commit-msg
   chmod +x .husky/prepare-commit-msg
   ```

### API Key not loading

1. Verify `.env` file exists and contains the key
2. Test environment loading:
   ```bash
   php scripts/debug-env.php
   ```

### Empty commit messages

If messages aren't being generated:
1. Check that you're using `git commit` without `-m`
2. Ensure there are staged changes: `git status`
3. Review logs: `cat /tmp/hook-debug.log`

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