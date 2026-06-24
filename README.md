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

This is a fork of [xExtension-AiSummary](https://github.com/deimosfr/xExtension-AiSummary). Instead of adding a summary below each article, it **replaces the article title** with an AI-rewritten, de-clickbaited version — and it does so **automatically**, with no button to click.

## Features

- **Automatic title rewriting** — As articles render, each headline is quietly replaced with a clearer, accurate, clickbait-free version. No button, no clicks.
- **Cached per article** — Each title is generated once and cached in your browser (`localStorage`), so revisiting an article doesn't call the API again.
- **Original preserved** — Rewritten titles are marked with a subtle underline; hover to see the original title in a tooltip.
- **Auto-fetch full articles** — When the RSS feed only contains a short excerpt, the extension fetches the original page to give the AI enough context for a good title.
- **4 AI providers** — Choose the one that fits your setup:

  | Provider | Default Model | API Key Required |
  |----------|--------------|:----------------:|
  | OpenAI (ChatGPT) | `gpt-4o-mini` | Yes |
  | Anthropic (Claude) | `claude-sonnet-4-6` | Yes |
  | Google (Gemini) | `gemini-2.5-flash` | Yes |
  | Ollama | `llama3.2` | No |

- **Custom prompts** — Override the default title-rewriting prompt. Use `{content}`, `{title}`, and `{language}` placeholders in your template.
- **Language override** — Choose the output language for rewritten titles independently of your FreshRSS UI language.
- **Theme-aware styling** — Adapts to light and dark FreshRSS themes.
- **14 languages** — cs, de, en, es, fr, it, ja, ko, nl, pl, pt-br, ru, tr, zh-cn.
- **Secure** — API keys stay server-side. All requests are proxied through PHP.

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
Article renders in FreshRSS
        |
        v
  PHP hook injects a hidden <div class="ai-title-marker" data-entry-id="…">
        |
        v
  script.js finds the marker (on load + via MutationObserver)
        |
        ├── Cached title in localStorage?  ── yes ──> swap headline instantly
        |
        └── no ──> POST ./?c=AiTitle&a=title ──> AiTitleController (PHP)
                                                      |
                                                      ├── Loads the entry
                                                      ├── If excerpt is too short, fetches the full article
                                                      ├── Strips HTML, truncates content
                                                      ├── Builds the title-rewrite prompt
                                                      └── Calls the AI provider (streamed via SSE)
                                                              |
                                                              v
                                          JS accumulates the streamed title,
                                          swaps the headline, and caches it
```

The original headline is kept in a `data-ai-original-title` attribute and shown as a hover tooltip.

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
├── extension.php               # Extension entrypoint (injects the marker)
├── configure.phtml             # Settings form
├── metadata.json               # Extension metadata
├── Controllers/
│   └── AiTitleController.php    # AI provider API proxy (title action)
├── static/
│   ├── script.js               # Automatic title replacement
│   └── style.css               # Styling (light + dark)
├── i18n/                       # Translations (14 languages)
├── tests/                      # PHPUnit test suite
└── .github/workflows/ci.yml    # CI pipeline
```

## Credits

Forked from [xExtension-AiSummary](https://github.com/deimosfr/xExtension-AiSummary) by Pierre Mavro (deimosfr). The AI provider proxy, streaming, and settings come from that project.

## License

This project is licensed under the [GNU General Public License v3.0](LICENSE).
