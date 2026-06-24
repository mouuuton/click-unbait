<p align="center">
  <img src="https://freshrss.org/images/icon.svg" alt="FreshRSS" width="60" />
</p>

<h1 align="center">Click Unbait for FreshRSS</h1>

<p align="center">
  <strong>Automatically rewrite clickbait article titles into clear, honest ones using your favorite AI provider.</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-%3E%3D8.1-8892BF?logo=php&logoColor=white" alt="PHP >= 8.1" />
  <img src="https://img.shields.io/badge/FreshRSS-Extension-green?logo=rss&logoColor=white" alt="FreshRSS Extension" />
</p>

---

This is a fork of [xExtension-AiSummary](https://github.com/deimosfr/xExtension-AiSummary). Instead of adding a summary below each article, it **replaces the article title** with an AI-rewritten, de-clickbaited version.

The rewriting happens **server-side, when feeds are refreshed** — the new title is saved to the database. That means it shows up **everywhere you read**: the FreshRSS web UI *and* external sync clients like **NetNewsWire**, Reeder, the official mobile apps, etc. (A browser-only approach can't do that, because external apps never load FreshRSS's web pages.)

## Features

- **Works in every client** — Titles are rewritten at ingestion and persisted, so NetNewsWire and other sync clients show the cleaned-up titles too, not just the web UI.
- **Fully automatic** — Runs during feed refresh; no button, no per-article clicking.
- **Generated once** — Each article's title is rewritten a single time, when it's first fetched, then stored.
- **Never blocks ingestion** — If the AI call fails or times out, the original title is kept and the article is saved normally.
- **4 AI providers** — Choose the one that fits your setup:

  | Provider | Default Model | API Key Required |
  |----------|--------------|:----------------:|
  | OpenAI (ChatGPT) | `gpt-4o-mini` | Yes |
  | Anthropic (Claude) | `claude-sonnet-4-6` | Yes |
  | Google (Gemini) | `gemini-2.5-flash` | Yes |
  | Ollama | `llama3.2` | No |

- **Custom prompts** — Override the default title-rewriting prompt. Use `{content}`, `{title}`, and `{language}` placeholders in your template.
- **Language override** — Choose the output language for rewritten titles independently of your FreshRSS UI language.
- **14 languages** — cs, de, en, es, fr, it, ja, ko, nl, pl, pt-br, ru, tr, zh-cn.
- **Secure** — API keys stay server-side. All requests are made from PHP.

> **Scope:** Only articles fetched *after* you enable and configure the extension are rewritten. Articles already in your database keep their original titles. For a local/free setup, Ollama with a small fast model (e.g. `llama3.2:1b`) is a good fit since every new article triggers one call during refresh.

## Installation

### From Git

```bash
cd /path/to/FreshRSS/extensions
git clone https://github.com/mouuuton/click-unbait.git click-Unbait
```

> The folder name is up to you (FreshRSS reads `metadata.json`, not the folder name); `click-Unbait` is used throughout these docs.

### Manual

1. Download the repository ZIP.
2. Extract it into your FreshRSS `extensions/` directory.
3. Rename the folder to `click-Unbait`.

### Enable

1. In FreshRSS, go to **Settings > Extensions**.
2. Enable **Click Unbait**.
3. Click the gear icon to configure your provider, API key, and model.

## Configuration

The settings are identical to the original AI Summary extension:

| Setting | Description |
|---------|-------------|
| **AI Provider** | Select OpenAI, Anthropic, Gemini, or Ollama. |
| **API Key** | Your provider's API key. Not required for Ollama. |
| **Model** | Leave empty to use the provider's default (see table above), or specify any model your provider supports. |
| **API URL** | Only for Ollama. Defaults to `http://localhost:11434`. |
| **Custom Prompt** | Override the prompt sent to the AI. Supports `{content}`, `{title}`, and `{language}` placeholders. Leave empty for the built-in default. |
| **Language** | Override the output language for rewritten titles. Defaults to your FreshRSS UI language. |
| **Request Timeout** | Maximum seconds to wait for the AI provider. Default 30, range 1–300. |

## How It Works

```
Feed refresh (cron / "Actualize")
        |
        v
  FreshRSS fetches a new article
        |
        v
  entry_before_insert hook (extension.php)
        |
        └──> AiTitleService::rewriteTitle()
                 ├── Strips HTML from the content, truncates it
                 ├── Builds the title-rewrite prompt ({title}, {content}, {language})
                 └── Calls the configured AI provider via cURL
                          |
                          v
                 new title  ──>  $entry->_title(newTitle)
        |
        v
  Article saved to the database with the rewritten title
        |
        v
  Served to ALL clients via the sync API
  (web UI, NetNewsWire, Reeder, official apps, …)
```

If the AI call fails or times out, the hook keeps the original title and the article is saved normally — ingestion is never blocked.

## Development

No build step required. PHP 8.1+ with the `curl` and `mbstring` extensions.

```bash
# Install test dependencies
composer install

# Run tests
vendor/bin/phpunit

# Run tests with verbose output
vendor/bin/phpunit --testdox

# Static analysis
vendor/bin/phpstan analyse

# Lint PHP files
php -l extension.php
```

### Project Structure

```
click-Unbait/
├── extension.php               # Entrypoint: registers entry_before_insert hook + settings
├── AiTitleService.php          # Shared AI logic: content → rewritten title (all 4 providers)
├── configure.phtml             # Settings form
├── metadata.json               # Extension metadata
├── i18n/                       # Translations (14 languages)
├── tests/                      # PHPUnit test suite
└── .github/workflows/ci.yml    # CI pipeline
```

## Credits

Forked from [xExtension-AiSummary](https://github.com/deimosfr/xExtension-AiSummary) by Pierre Mavro (deimosfr). The AI provider proxy, streaming, and settings come from that project.

## License

This project is licensed under the [GNU General Public License v3.0](LICENSE).
