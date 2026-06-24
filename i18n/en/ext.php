<?php

declare(strict_types=1);

return [
	'ai_title' => [
		'provider' => 'AI Provider',
		'api_key' => 'API Key',
		'api_key_help' => 'Not required for Ollama.',
		'model' => 'Model',
		'model_placeholder' => 'Leave empty for default',
		'model_help' => 'Defaults: OpenAI: gpt-4o-mini, Claude: claude-sonnet-4-6, Gemini: gemini-2.5-flash, Ollama: llama3.2',
		'api_url' => 'API URL',
		'api_url_help' => 'Only required for Ollama. Default: http://localhost:11434',
		'prompt' => 'Custom Prompt',
		'prompt_placeholder' => 'Rewrite the article title to be clear, accurate, and free of clickbait. Respond with only the new title, written in {language}. Placeholders: {language}, {title}, {content}.',
		'prompt_help' => 'Leave empty to use the default prompt. Available placeholders: {language}, {title}, {content}',
		'language' => 'Title Language',
		'language_auto' => 'Auto (use FreshRSS language)',
		'language_help' => 'Language used for rewritten titles. Auto uses your FreshRSS interface language.',
		'timeout' => 'Request Timeout (seconds)',
		'timeout_help' => 'Maximum seconds to wait for the AI provider to respond. Default: 30. Range: 1-300.',
		'save' => 'Save',
	],
];
