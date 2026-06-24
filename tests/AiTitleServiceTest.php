<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AiTitleServiceTest extends TestCase {

	private AiTitleService $service;

	protected function setUp(): void {
		FreshRSS_Context::init();
		$this->service = new AiTitleService();
	}

	// ── Constants ──

	public function testDefaultModelsAreDefined(): void {
		self::assertArrayHasKey('openai', AiTitleService::DEFAULT_MODELS);
		self::assertArrayHasKey('anthropic', AiTitleService::DEFAULT_MODELS);
		self::assertArrayHasKey('gemini', AiTitleService::DEFAULT_MODELS);
		self::assertArrayHasKey('ollama', AiTitleService::DEFAULT_MODELS);
	}

	public function testDefaultOllamaUrl(): void {
		self::assertSame('http://localhost:11434', AiTitleService::DEFAULT_OLLAMA_URL);
	}

	public function testMaxTokensAndContentLengthArePositive(): void {
		self::assertGreaterThan(0, AiTitleService::MAX_TOKENS);
		self::assertGreaterThan(0, AiTitleService::MAX_CONTENT_LENGTH);
	}

	public function testDefaultPromptTargetsTitleRewriting(): void {
		$prompt = AiTitleService::DEFAULT_PROMPT;
		self::assertStringContainsString('{title}', $prompt);
		self::assertStringContainsString('{content}', $prompt);
		self::assertStringContainsString('{language}', $prompt);
		self::assertStringContainsStringIgnoringCase('clickbait', $prompt);
	}

	// ── readConfig ──

	public function testReadConfigDefaults(): void {
		$cfg = AiTitleService::readConfig(FreshRSS_Context::$user_conf);
		self::assertSame('openai', $cfg['provider']);
		self::assertSame('', $cfg['apiKey']);
		self::assertSame(AiTitleExtension::TIMEOUT_DEFAULT, $cfg['timeout']);
	}

	public function testReadConfigFallsBackToUiLanguage(): void {
		FreshRSS_Context::$user_conf->ai_title_language = '';
		FreshRSS_Context::$user_conf->language = 'fr';
		$cfg = AiTitleService::readConfig(FreshRSS_Context::$user_conf);
		self::assertSame('fr', $cfg['language']);
	}

	public function testReadConfigClampsInvalidTimeout(): void {
		FreshRSS_Context::$user_conf->ai_title_timeout = 99999;
		$cfg = AiTitleService::readConfig(FreshRSS_Context::$user_conf);
		self::assertSame(AiTitleExtension::TIMEOUT_DEFAULT, $cfg['timeout']);
	}

	// ── isConfigured ──

	public function testIsConfiguredRequiresKeyExceptOllama(): void {
		$base = ['provider' => 'openai', 'apiKey' => '', 'model' => '', 'apiUrl' => '', 'prompt' => '', 'language' => 'en', 'timeout' => 30];
		self::assertFalse(AiTitleService::isConfigured($base));
		self::assertTrue(AiTitleService::isConfigured(['provider' => 'openai', 'apiKey' => 'sk', 'model' => '', 'apiUrl' => '', 'prompt' => '', 'language' => 'en', 'timeout' => 30]));
		self::assertTrue(AiTitleService::isConfigured(['provider' => 'ollama', 'apiKey' => '', 'model' => '', 'apiUrl' => '', 'prompt' => '', 'language' => 'en', 'timeout' => 30]));
	}

	// ── rewriteTitle short-circuits without network when unconfigured ──

	public function testRewriteTitleReturnsNullWhenUnconfigured(): void {
		$cfg = ['provider' => 'openai', 'apiKey' => '', 'model' => '', 'apiUrl' => '', 'prompt' => '', 'language' => 'en', 'timeout' => 30];
		self::assertNull($this->service->rewriteTitle('Some title', '<p>Body</p>', $cfg));
	}

	// ── buildPrompts ──

	public function testBuildPromptsSubstitutesPlaceholders(): void {
		$cfg = ['provider' => 'openai', 'apiKey' => 'k', 'model' => '', 'apiUrl' => '', 'prompt' => 'Title: {title} / Lang: {language} / Body: {content}', 'language' => 'fr', 'timeout' => 30];
		[$system, $user] = $this->service->buildPrompts('Hello', 'Body text', $cfg);
		self::assertSame('', $system);
		self::assertStringContainsString('Hello', $user);
		self::assertStringContainsString('French', $user); // fr → French
		self::assertStringContainsString('Body text', $user);
	}

	public function testBuildPromptsLegacyPromptUsesSystemMessage(): void {
		$cfg = ['provider' => 'openai', 'apiKey' => 'k', 'model' => '', 'apiUrl' => '', 'prompt' => 'Summarize the title nicely', 'language' => 'en', 'timeout' => 30];
		[$system, $user] = $this->service->buildPrompts('My Headline', 'Article body', $cfg);
		self::assertSame('Summarize the title nicely', $system);
		self::assertStringContainsString('My Headline', $user);
		self::assertStringContainsString('Article body', $user);
	}

	// ── extractText ──

	public function testExtractTextStripsHtmlAndNormalizes(): void {
		$result = $this->service->extractText('<p>Hello  <b>world</b></p>  <br>  test');
		self::assertSame('Hello world test', $result);
	}

	public function testExtractTextDecodesEntities(): void {
		$result = $this->service->extractText('&amp; &lt;tag&gt; &quot;quoted&quot;');
		self::assertSame('& <tag> "quoted"', $result);
	}

	// ── cleanTitle ──

	public function testCleanTitleStripsWrappingQuotes(): void {
		self::assertSame('A clear title', AiTitleService::cleanTitle('"A clear title"'));
		self::assertSame('A clear title', AiTitleService::cleanTitle("“A clear title”"));
		self::assertSame('A clear title', AiTitleService::cleanTitle("'A clear title'"));
	}

	public function testCleanTitleCollapsesWhitespaceAndTakesFirstLine(): void {
		self::assertSame('First line', AiTitleService::cleanTitle("  First   line  \nSecond line"));
	}

	public function testCleanTitleEmptyStaysEmpty(): void {
		self::assertSame('', AiTitleService::cleanTitle('   '));
	}
}
