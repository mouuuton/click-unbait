# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

FreshRSS extension that **automatically rewrites clickbait article titles** using AI. Instead of adding a summary, it replaces each article's headline in place — with no button to click. Supports OpenAI (ChatGPT), Anthropic (Claude), Google (Gemini), and Ollama as AI providers. Licensed under GPL v3.

This is a fork of [xExtension-AiSummary](https://github.com/deimosfr/xExtension-AiSummary); the AI provider proxy, SSE streaming, and settings are inherited from it.

## Architecture

This is a FreshRSS extension (PHP 8.1+ / JS). The extension class is `AiTitleExtension` (entrypoint: `AiTitle`).

**Request flow:** Article renders → PHP `entry_before_display` hook injects a hidden `<div class="ai-title-marker" data-entry-id="…">` → `script.js` finds the marker automatically (on load + via MutationObserver) → if a title is cached in `localStorage` it swaps the headline instantly, otherwise it POSTs to `AiTitleController::titleAction()` → PHP loads the entry, strips HTML, calls the configured AI provider via curl (streamed as SSE) → JS accumulates the streamed title, swaps the headline, and caches it.

### Key Files

- `extension.php` — Main extension class. Registers the `entry_before_display` hook (injects the marker), loads static assets, handles configuration save. User config is prefixed `ai_title_`.
- `Controllers/AiTitleController.php` — Backend endpoint (`titleAction`) that proxies AI API calls. Provider-specific methods (`callOpenAI`, `callAnthropic`, `callGemini`, `callOllama`). The default prompt rewrites titles to remove clickbait. API keys never leave the server.
- `configure.phtml` — Settings form (provider, API key, model, API URL for Ollama, custom prompt, language, timeout).
- `static/script.js` — Automatic title replacement: marker discovery via MutationObserver + poll, a small concurrency-limited fetch queue, SSE accumulation, `localStorage` caching, original-title preservation.
- `static/style.css` — Hides the marker; subtle gradient underline on replaced titles (light + dark themes).
- `i18n/*/ext.php` — Translation strings (14 languages).

### FreshRSS Extension Conventions

- Class name must be `{entrypoint}Extension` matching `metadata.json`'s `entrypoint` field.
- User config is stored as dynamic properties on `FreshRSS_Context::$user_conf` (prefixed `ai_title_`).
- Controller class follows `FreshExtension_{Name}_Controller` naming and is registered via `$this->registerController()`.
- CSRF token: forms use `FreshRSS_Auth::csrfToken()`, JS uses `context.csrf`.
- Controller URLs: `./?c=AiTitle&a=title`.

## Commands

```bash
composer install                              # install dependencies (first time)
vendor/bin/phpunit                            # run all tests
vendor/bin/phpunit --testdox                  # run tests with verbose labels
vendor/bin/phpunit --filter testMethodName    # run a single test
vendor/bin/phpstan analyse                    # static analysis (level 10)
php -l extension.php                          # lint a PHP file
```

No build step required. To install the extension locally, symlink or copy this directory into FreshRSS's `extensions/` folder, named `click-Unbait` (the folder name is arbitrary — FreshRSS reads `metadata.json`, not the directory name).

## Testing

Tests use FreshRSS framework stubs in `tests/stubs/` (not the real FreshRSS). Test suites:
- `AiTitleControllerTest` — Controller validation, error paths, JSON output, method signatures, default prompt content.
- `AiTitleExtensionTest` — Marker HTML injection, XSS escaping, no-button guarantee, config save.
- `I18nTest` — All translation files have required keys, no extras, non-empty values, correct model defaults. Uses `@dataProvider` to run against all 14 languages.
- `MetadataTest` — metadata.json structure, class existence, semver format.

When adding a new i18n language, add the translation file at `i18n/{code}/ext.php` with all keys from `en/ext.php` (note the top-level array key is `ai_title`). The `I18nTest` will automatically pick it up via `languageProvider()`.
