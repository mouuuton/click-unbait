<?php

declare(strict_types=1);

/**
 * Shared AI logic: turns an article (title + content) into a rewritten,
 * de-clickbaited title. Used server-side from the `entry_before_insert`
 * hook so the new title is persisted and served to every client
 * (web UI, NetNewsWire, official apps, …), not just the browser.
 *
 * `rewriteTitle()` never throws — on any failure it returns null so feed
 * ingestion is never blocked.
 */
final class AiTitleService {

	/** @var array<string, string> */
	public const DEFAULT_MODELS = [
		'openai' => 'gpt-4o-mini',
		'anthropic' => 'claude-sonnet-4-6',
		'gemini' => 'gemini-2.5-flash',
		'ollama' => 'llama3.2',
	];

	public const DEFAULT_OLLAMA_URL = 'http://localhost:11434';

	private const CONNECT_TIMEOUT = 10;

	// Titles are short, so the AI only needs a modest output budget.
	public const MAX_TOKENS = 120;

	public const MAX_CONTENT_LENGTH = 6000;

	private int $requestTimeout = 30;

	public const DEFAULT_PROMPT = <<<'PROMPT'
You are a skilled news editor who removes clickbait. Rewrite the article's title so it is clear, accurate, specific, and honest. Strip out sensationalism, curiosity gaps, vague teasers ("you won't believe…"), listicle hooks, and emotional manipulation. Keep the new title concise (ideally under 15 words), faithful to what the article actually says, and written in {language}.

Respond with ONLY the rewritten title — a single line, with no surrounding quotation marks, no label, and no explanation.

Original title: {title}
Article content: {content}
PROMPT;

	/** @var array<string, string> */
	public const LANGUAGE_NAMES = [
		'cs' => 'Czech',
		'de' => 'German',
		'en' => 'English',
		'es' => 'Spanish',
		'fr' => 'French',
		'it' => 'Italian',
		'ja' => 'Japanese',
		'ko' => 'Korean',
		'nl' => 'Dutch',
		'pl' => 'Polish',
		'pt-br' => 'Portuguese',
		'ru' => 'Russian',
		'tr' => 'Turkish',
		'zh-cn' => 'Chinese',
	];

	/**
	 * Read and normalise the extension settings from the user configuration.
	 *
	 * @return array{provider: string, apiKey: string, model: string, apiUrl: string, prompt: string, language: string, timeout: int}
	 */
	public static function readConfig(FreshRSS_UserConfiguration $conf): array {
		/** @var mixed */
		$provider = $conf->ai_title_provider;
		$provider = is_string($provider) && $provider !== '' ? $provider : 'openai';
		/** @var mixed */
		$apiKey = $conf->ai_title_api_key;
		$apiKey = is_string($apiKey) ? $apiKey : '';
		/** @var mixed */
		$model = $conf->ai_title_model;
		$model = is_string($model) ? $model : '';
		/** @var mixed */
		$apiUrl = $conf->ai_title_api_url;
		$apiUrl = is_string($apiUrl) ? $apiUrl : '';
		/** @var mixed */
		$prompt = $conf->ai_title_prompt;
		$prompt = is_string($prompt) ? $prompt : '';
		/** @var mixed */
		$langCode = $conf->ai_title_language;
		$langCode = is_string($langCode) ? $langCode : '';
		if ($langCode === '') {
			/** @var mixed */
			$uiLang = $conf->language;
			$langCode = is_string($uiLang) ? $uiLang : 'en';
		}
		/** @var mixed */
		$timeout = $conf->ai_title_timeout;
		$timeout = is_int($timeout) && $timeout >= AiTitleExtension::TIMEOUT_MIN && $timeout <= AiTitleExtension::TIMEOUT_MAX
			? $timeout
			: AiTitleExtension::TIMEOUT_DEFAULT;

		return [
			'provider' => $provider,
			'apiKey' => $apiKey,
			'model' => $model,
			'apiUrl' => $apiUrl,
			'prompt' => $prompt,
			'language' => $langCode,
			'timeout' => $timeout,
		];
	}

	/**
	 * Whether the given config is usable (a key is present unless using Ollama).
	 *
	 * @param array{provider: string, apiKey: string, model: string, apiUrl: string, prompt: string, language: string, timeout: int} $cfg
	 */
	public static function isConfigured(array $cfg): bool {
		return $cfg['provider'] === 'ollama' || $cfg['apiKey'] !== '';
	}

	/**
	 * Generate a rewritten title. Returns null on failure, empty result, or
	 * when not configured. Never throws.
	 *
	 * @param array{provider: string, apiKey: string, model: string, apiUrl: string, prompt: string, language: string, timeout: int} $cfg
	 */
	public function rewriteTitle(string $title, string $content, array $cfg): ?string {
		if (!self::isConfigured($cfg)) {
			return null;
		}
		$this->requestTimeout = $cfg['timeout'];

		$text = $this->extractText($content);
		$text = mb_substr($text, 0, self::MAX_CONTENT_LENGTH);

		[$systemPrompt, $userPrompt] = $this->buildPrompts($title, $text, $cfg);

		$buffer = '';
		$sink = function (string $chunk) use (&$buffer): void {
			$buffer .= $chunk;
		};

		try {
			$this->generate($cfg, $systemPrompt, $userPrompt, $sink);
		} catch (\Throwable $e) {
			return null;
		}

		$clean = self::cleanTitle($buffer);
		return $clean !== '' ? $clean : null;
	}

	/**
	 * @param array{provider: string, apiKey: string, model: string, apiUrl: string, prompt: string, language: string, timeout: int} $cfg
	 * @return array{0: string, 1: string} [systemPrompt, userPrompt]
	 */
	public function buildPrompts(string $title, string $content, array $cfg): array {
		$promptTemplate = $cfg['prompt'] !== '' ? $cfg['prompt'] : self::DEFAULT_PROMPT;
		$language = self::LANGUAGE_NAMES[$cfg['language']] ?? $cfg['language'];

		// Template prompt: placeholders substituted, sent as the user message.
		// Legacy prompt (no {content}): used as system message with article as user message.
		if (str_contains($promptTemplate, '{content}')) {
			return [
				'',
				str_replace(['{language}', '{title}', '{content}'], [$language, $title, $content], $promptTemplate),
			];
		}
		return [$promptTemplate, 'Title: ' . $title . "\n\n" . $content];
	}

	/**
	 * @param array{provider: string, apiKey: string, model: string, apiUrl: string, prompt: string, language: string, timeout: int} $cfg
	 */
	private function generate(array $cfg, string $systemPrompt, string $userPrompt, callable $onText): void {
		$provider = $cfg['provider'];
		$model = $cfg['model'];
		match ($provider) {
			'openai' => $this->callOpenAI($cfg['apiKey'], $model !== '' ? $model : self::DEFAULT_MODELS['openai'], $systemPrompt, $userPrompt, $onText),
			'anthropic' => $this->callAnthropic($cfg['apiKey'], $model !== '' ? $model : self::DEFAULT_MODELS['anthropic'], $systemPrompt, $userPrompt, $onText),
			'gemini' => $this->callGemini($cfg['apiKey'], $model !== '' ? $model : self::DEFAULT_MODELS['gemini'], $systemPrompt, $userPrompt, $onText),
			'ollama' => $this->callOllama(
				$cfg['apiUrl'] !== '' ? $cfg['apiUrl'] : self::DEFAULT_OLLAMA_URL,
				$model !== '' ? $model : self::DEFAULT_MODELS['ollama'],
				$systemPrompt,
				$userPrompt,
				$onText,
			),
			default => throw new \RuntimeException('Unknown provider: ' . $provider),
		};
	}

	private function callOpenAI(string $apiKey, string $model, string $systemPrompt, string $userPrompt, callable $onText): void {
		$messages = [];
		if ($systemPrompt !== '') {
			$messages[] = ['role' => 'system', 'content' => $systemPrompt];
		}
		$messages[] = ['role' => 'user', 'content' => $userPrompt];

		$this->curlStreamRequest(
			'https://api.openai.com/v1/chat/completions',
			['model' => $model, 'messages' => $messages, 'max_tokens' => self::MAX_TOKENS, 'stream' => true],
			['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
			function (string $line) use ($onText): void {
				if (!str_starts_with($line, 'data: ')) {
					return;
				}
				$json = substr($line, 6);
				if (trim($json) === '[DONE]') {
					return;
				}
				$data = json_decode($json, true);
				if (is_array($data)) {
					/** @var mixed */
					$choices = $data['choices'] ?? null;
					if (is_array($choices) && isset($choices[0]) && is_array($choices[0])) {
						/** @var mixed */
						$delta = $choices[0]['delta'] ?? null;
						if (is_array($delta)) {
							$text = $delta['content'] ?? '';
							if (is_string($text) && $text !== '') {
								$onText($text);
							}
						}
					}
				}
			},
		);
	}

	private function callAnthropic(string $apiKey, string $model, string $systemPrompt, string $userPrompt, callable $onText): void {
		$payload = [
			'model' => $model,
			'max_tokens' => self::MAX_TOKENS,
			'stream' => true,
			'messages' => [
				['role' => 'user', 'content' => $userPrompt],
			],
		];
		if ($systemPrompt !== '') {
			$payload['system'] = $systemPrompt;
		}

		$this->curlStreamRequest(
			'https://api.anthropic.com/v1/messages',
			$payload,
			['x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
			function (string $line) use ($onText): void {
				if (!str_starts_with($line, 'data: ')) {
					return;
				}
				$data = json_decode(substr($line, 6), true);
				if (is_array($data) && (($data['type'] ?? '') === 'content_block_delta')) {
					/** @var mixed */
					$delta = $data['delta'] ?? null;
					if (is_array($delta)) {
						$text = $delta['text'] ?? '';
						if (is_string($text) && $text !== '') {
							$onText($text);
						}
					}
				}
			},
		);
	}

	private function callGemini(string $apiKey, string $model, string $systemPrompt, string $userPrompt, callable $onText): void {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/'
			. urlencode($model)
			. ':streamGenerateContent?alt=sse&key=' . urlencode($apiKey);

		$payload = [
			'contents' => [
				['parts' => [['text' => $userPrompt]]],
			],
		];
		if ($systemPrompt !== '') {
			$payload['system_instruction'] = [
				'parts' => [['text' => $systemPrompt]],
			];
		}

		$this->curlStreamRequest(
			$url,
			$payload,
			['Content-Type: application/json'],
			function (string $line) use ($onText): void {
				if (!str_starts_with($line, 'data: ')) {
					return;
				}
				$data = json_decode(substr($line, 6), true);
				if (is_array($data)) {
					/** @var mixed */
					$candidates = $data['candidates'] ?? null;
					if (is_array($candidates) && isset($candidates[0]) && is_array($candidates[0])) {
						/** @var mixed */
						$contentNode = $candidates[0]['content'] ?? null;
						if (is_array($contentNode)) {
							/** @var mixed */
							$parts = $contentNode['parts'] ?? null;
							if (is_array($parts) && isset($parts[0]) && is_array($parts[0])) {
								$text = $parts[0]['text'] ?? '';
								if (is_string($text) && $text !== '') {
									$onText($text);
								}
							}
						}
					}
				}
			},
		);
	}

	private function callOllama(string $apiUrl, string $model, string $systemPrompt, string $userPrompt, callable $onText): void {
		$url = rtrim($apiUrl, '/') . '/api/chat';

		$messages = [];
		if ($systemPrompt !== '') {
			$messages[] = ['role' => 'system', 'content' => $systemPrompt];
		}
		$messages[] = ['role' => 'user', 'content' => $userPrompt];

		$this->curlStreamRequest(
			$url,
			['model' => $model, 'messages' => $messages],
			['Content-Type: application/json'],
			function (string $line) use ($onText): void {
				$data = json_decode($line, true);
				if (!is_array($data)) {
					return;
				}
				if (isset($data['error'])) {
					$errorObj = $data['error'];
					$msg = is_string($errorObj) ? $errorObj : (is_array($errorObj) ? ($errorObj['message'] ?? 'Unknown error') : 'Unknown error');
					throw new \RuntimeException('Ollama: ' . (is_string($msg) ? $msg : 'Unknown error'));
				}
				/** @var mixed */
				$messageNode = $data['message'] ?? null;
				if (is_array($messageNode)) {
					$text = $messageNode['content'] ?? '';
					if (is_string($text) && $text !== '') {
						$onText($text);
					}
				}
			},
		);
	}

	public function extractText(string $html): string {
		$text = strip_tags($html);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return (string) preg_replace('/\s+/', ' ', trim($text));
	}

	/**
	 * Normalise an AI-produced title: single line, no wrapping quotes.
	 */
	public static function cleanTitle(string $text): string {
		// Take the first non-empty line (guards against models that append an
		// explanation on a new line), then normalise whitespace and quotes.
		$lines = preg_split('/\r\n|\r|\n/', $text);
		$t = '';
		if ($lines !== false) {
			foreach ($lines as $line) {
				if (trim($line) !== '') {
					$t = $line;
					break;
				}
			}
		}
		$t = trim((string) preg_replace('/\s+/', ' ', $t));
		$t = (string) preg_replace('/^["\'\x{201C}\x{2018}\x{00AB}]\s*/u', '', $t);
		$t = (string) preg_replace('/\s*["\'\x{201D}\x{2019}\x{00BB}]$/u', '', $t);
		return trim($t);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param list<string> $headers
	 */
	private function curlStreamRequest(string $url, array $payload, array $headers, callable $lineHandler): void {
		$ch = curl_init($url);
		if ($ch === false) {
			throw new \RuntimeException('Failed to initialize cURL');
		}

		$buffer = '';
		$rawResponse = '';
		$error = null;

		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT => $this->requestTimeout,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$buffer, &$rawResponse, $lineHandler, &$error): int {
				if (strlen($rawResponse) < 4096) {
					$rawResponse .= $data;
				}
				$buffer .= $data;
				while (($pos = strpos($buffer, "\n")) !== false) {
					$line = trim(substr($buffer, 0, $pos));
					$buffer = substr($buffer, $pos + 1);
					if ($line !== '') {
						try {
							$lineHandler($line);
						} catch (\Exception $e) {
							$error = $e;
							return 0;
						}
					}
				}
				return strlen($data);
			},
		]);

		curl_exec($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		unset($ch);

		if ($error !== null) {
			throw $error;
		}

		$remaining = trim($buffer);
		if ($remaining !== '') {
			$lineHandler($remaining);
		}

		if ($httpCode === 0 && $curlError !== '') {
			throw new \RuntimeException('HTTP request failed: ' . $curlError);
		}

		if ($httpCode >= 400) {
			$errorData = json_decode($rawResponse, true);
			if (is_array($errorData)) {
				/** @var mixed */
				$errorObj = $errorData['error'] ?? null;
				if (is_array($errorObj)) {
					$msg = $errorObj['message'] ?? $errorObj;
				} else {
					$msg = $errorObj ?? 'HTTP ' . $httpCode;
				}
				throw new \RuntimeException('API error: ' . (is_string($msg) ? $msg : (string) json_encode($msg)));
			}
			throw new \RuntimeException('API error: HTTP ' . $httpCode);
		}
	}
}
