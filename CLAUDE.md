# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

FreshRSS extension that **automatically rewrites clickbait article titles** using AI. It replaces each article's stored title at ingestion time, so the cleaned-up title is served to every sync client (web UI, NetNewsWire, Reeder, official apps, …) — not just the browser. Supports OpenAI (ChatGPT), Anthropic (Claude), Google (Gemini), and Ollama as AI providers. Licensed under GPL v3.

This is a fork of [xExtension-AiSummary](https://github.com/deimosfr/xExtension-AiSummary); the AI provider integrations and settings are inherited from it.

## Architecture

This is a server-side FreshRSS extension (PHP 8.1+, no frontend JS). The extension class is `AiTitleExtension` (entrypoint: `AiTitle`).

**Flow:** Feed refresh fetches a new article → the `entry_before_insert` hook (`AiTitleExtension::entryBeforeInsertHook`) calls `AiTitleService::rewriteTitle()` → the service strips HTML, builds the prompt, calls the configured provider via cURL, and returns the cleaned title → the hook sets `$entry->_title(newTitle)` → FreshRSS persists the rewritten title to the database → the sync API serves it to all clients.

Key design points:
- The hook **never throws**; on any AI failure it keeps the original title so ingestion is never blocked.
- Only articles fetched **after** enabling are rewritten (the hook fires on insert). Already-stored articles are untouched.
- Each title is generated **once** (at insert) and stored — no per-view or repeated calls.
- The AI call is **synchronous during refresh**, so a slow/cold provider (e.g. Ollama on CPU) slows refresh; a small fast model + a sane timeout (`ai_title_timeout`, max 300s) are recommended.

### Key Files

- `extension.php` — Extension class. Registers the `entry_before_insert` hook and handles configuration save. `require_once`s `AiTitleService.php`. User config is prefixed `ai_title_`.
- `AiTitleService.php` — All AI logic, decoupled from any controller/request. `readConfig()` / `isConfigured()` read settings; `buildPrompts()` substitutes `{title}`/`{content}`/`{language}`; `rewriteTitle()` runs the provider call and returns the cleaned title (or null). Provider methods `callOpenAI`/`callAnthropic`/`callGemini`/`callOllama` stream via cURL into a text sink; `cleanTitle()` normalises the result (first line, strip wrapping quotes). API keys never leave the server.
- `configure.phtml` — Settings form (provider, API key, model, API URL for Ollama, custom prompt, language, timeout).
- `i18n/*/ext.php` — Translation strings (14 languages).

### FreshRSS Extension Conventions

- Class name must be `{entrypoint}Extension` matching `metadata.json`'s `entrypoint` field.
- User config is stored as dynamic properties on `FreshRSS_Context::$user_conf` (prefixed `ai_title_`).
- `entry_before_insert` runs during feed refresh, receives a `FreshRSS_Entry`, and its modifications persist to the DB (unlike `entry_before_display`, which only affects the web render).

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

Tests use FreshRSS framework stubs in `tests/stubs/` (not the real FreshRSS) and never hit the network — the AI path is only exercised up to its "not configured" short-circuit. Test suites:
- `AiTitleServiceTest` — Constants, `readConfig` defaults/clamping/language fallback, `isConfigured`, `buildPrompts` placeholder substitution, `extractText`, `cleanTitle`, and `rewriteTitle` returning null when unconfigured.
- `AiTitleExtensionTest` — `entry_before_insert` hook is a no-op when unconfigured (keeps original title, no network), returns the same instance, and config save/timeout coercion.
- `I18nTest` — All translation files have required keys, no extras, non-empty values, correct model defaults. Uses `@dataProvider` to run against all 14 languages.
- `MetadataTest` — metadata.json structure, extension + service class existence, semver format.

When adding a new i18n language, add the translation file at `i18n/{code}/ext.php` with all keys from `en/ext.php` (note the top-level array key is `ai_title`). The `I18nTest` will automatically pick it up via `languageProvider()`.
