<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AiTitleExtensionTest extends TestCase {

	private AiTitleExtension $extension;

	protected function setUp(): void {
		FreshRSS_Context::init();
		$this->extension = new AiTitleExtension();
	}

	public function testInsertHookLeavesTitleWhenNotConfigured(): void {
		// No provider/API key set → hook must be a no-op (no network).
		$entry = new FreshRSS_Entry('1', 'Original title', '<p>Body</p>');

		$result = $this->extension->entryBeforeInsertHook($entry);

		self::assertSame('Original title', $result->title());
	}

	public function testInsertHookReturnsSameEntryInstance(): void {
		$entry = new FreshRSS_Entry('1', 'T', '<p>C</p>');

		$result = $this->extension->entryBeforeInsertHook($entry);

		self::assertSame($entry, $result);
	}

	public function testInsertHookLeavesTitleWhenOpenAiKeyMissing(): void {
		FreshRSS_Context::$user_conf->ai_title_provider = 'openai';
		FreshRSS_Context::$user_conf->ai_title_api_key = '';
		$entry = new FreshRSS_Entry('1', 'Keep me', '<p>Body</p>');

		$result = $this->extension->entryBeforeInsertHook($entry);

		self::assertSame('Keep me', $result->title());
	}

	public function testHandleConfigureActionSavesConfig(): void {
		Minz_Request::setParam('_method', 'POST');
		Minz_Request::setParam('_csrf', 'test-csrf-token');
		Minz_Request::setParam('ai_title_provider', 'anthropic');
		Minz_Request::setParam('ai_title_api_key', 'sk-ant-test');
		Minz_Request::setParam('ai_title_model', 'claude-sonnet-4-6');
		Minz_Request::setParam('ai_title_api_url', '');
		Minz_Request::setParam('ai_title_prompt', 'Custom prompt here');
		Minz_Request::setParam('ai_title_timeout', '45');

		$this->extension->handleConfigureAction();

		self::assertSame('anthropic', FreshRSS_Context::$user_conf->ai_title_provider);
		self::assertSame('sk-ant-test', FreshRSS_Context::$user_conf->ai_title_api_key);
		self::assertSame('claude-sonnet-4-6', FreshRSS_Context::$user_conf->ai_title_model);
		self::assertSame('Custom prompt here', FreshRSS_Context::$user_conf->ai_title_prompt);
		self::assertSame(45, FreshRSS_Context::$user_conf->ai_title_timeout);
	}

	public function testHandleConfigureActionCoercesInvalidTimeout(): void {
		Minz_Request::setParam('_method', 'POST');
		Minz_Request::setParam('_csrf', 'test-csrf-token');
		Minz_Request::setParam('ai_title_provider', 'openai');
		Minz_Request::setParam('ai_title_timeout', '0');
		$this->extension->handleConfigureAction();
		self::assertSame(30, FreshRSS_Context::$user_conf->ai_title_timeout);

		Minz_Request::setParam('ai_title_timeout', '999');
		$this->extension->handleConfigureAction();
		self::assertSame(30, FreshRSS_Context::$user_conf->ai_title_timeout);

		Minz_Request::setParam('ai_title_timeout', '');
		$this->extension->handleConfigureAction();
		self::assertSame(30, FreshRSS_Context::$user_conf->ai_title_timeout);
	}
}
