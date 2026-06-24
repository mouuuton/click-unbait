<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AiTitleExtensionTest extends TestCase {

	private AiTitleExtension $extension;

	protected function setUp(): void {
		FreshRSS_Context::init();
		$this->extension = new AiTitleExtension();
	}

	public function testEntryBeforeDisplayHookInjectsMarker(): void {
		$entry = new FreshRSS_Entry('123', 'My Article', '<p>Original content</p>');

		$result = $this->extension->entryBeforeDisplayHook($entry);

		self::assertStringContainsString('ai-title-marker', $result->content());
	}

	public function testHookPreservesOriginalContent(): void {
		$original = '<p>My article content</p>';
		$entry = new FreshRSS_Entry('123', 'Title', $original);

		$result = $this->extension->entryBeforeDisplayHook($entry);

		self::assertStringContainsString($original, $result->content());
	}

	public function testHookInjectsBeforeOriginalContent(): void {
		$original = '<p>Original</p>';
		$entry = new FreshRSS_Entry('123', 'Title', $original);

		$result = $this->extension->entryBeforeDisplayHook($entry);

		$markerPos = strpos($result->content(), 'ai-title-marker');
		$originalPos = strpos($result->content(), '<p>Original</p>');
		self::assertLessThan($originalPos, $markerPos);
	}

	public function testHookSetsCorrectEntryId(): void {
		$entry = new FreshRSS_Entry('456', 'Title', '<p>Content</p>');

		$result = $this->extension->entryBeforeDisplayHook($entry);

		self::assertStringContainsString('data-entry-id="456"', $result->content());
	}

	public function testHookEscapesEntryId(): void {
		$entry = new FreshRSS_Entry('12"3<4', 'Title', '<p>Content</p>');

		$result = $this->extension->entryBeforeDisplayHook($entry);

		// ID should be escaped - no raw quotes or angle brackets in the attribute
		self::assertStringNotContainsString('data-entry-id="12"3<4"', $result->content());
		self::assertStringContainsString('data-entry-id="12&quot;3&lt;4"', $result->content());
	}

	public function testHookReturnsSameEntryInstance(): void {
		$entry = new FreshRSS_Entry('1', 'T', '<p>C</p>');

		$result = $this->extension->entryBeforeDisplayHook($entry);

		self::assertSame($entry, $result);
	}

	public function testHookDoesNotInjectAnyButton(): void {
		$entry = new FreshRSS_Entry('1', 'T', '<p>C</p>');

		$result = $this->extension->entryBeforeDisplayHook($entry);

		// Title replacement is automatic — there must be no clickable control.
		self::assertStringNotContainsString('<button', $result->content());
	}

	public function testHookInjectsExactMarkup(): void {
		$entry = new FreshRSS_Entry('1', 'T', '<p>C</p>');

		$this->extension->entryBeforeDisplayHook($entry);

		$this->assertStringContainsString('<div class="ai-title-marker" data-entry-id="1"></div>', $entry->content());
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
