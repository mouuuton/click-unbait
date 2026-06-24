<?php

declare(strict_types=1);

final class AiTitleExtension extends Minz_Extension {

	public const TIMEOUT_MIN = 1;
	public const TIMEOUT_MAX = 300;
	public const TIMEOUT_DEFAULT = 30;

	#[\Override]
	public function init(): void {
		Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
		Minz_View::appendScript($this->getFileUrl('script.js', 'js'));
		$this->registerTranslates();
		$this->registerHook('entry_before_display', [$this, 'entryBeforeDisplayHook']);
		$this->registerController('AiTitle');
	}

	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			if (Minz_Request::paramString('_csrf') !== FreshRSS_Auth::csrfToken()) {
				Minz_Error::error(403);
			}
			$user_conf = FreshRSS_Context::userConf();
			$user_conf->_attribute('ai_title_provider', Minz_Request::paramString('ai_title_provider') ?: 'openai');
			$user_conf->_attribute('ai_title_api_key', Minz_Request::paramString('ai_title_api_key'));
			$user_conf->_attribute('ai_title_model', Minz_Request::paramString('ai_title_model'));
			$user_conf->_attribute('ai_title_api_url', Minz_Request::paramString('ai_title_api_url'));
			$user_conf->_attribute('ai_title_prompt', Minz_Request::paramString('ai_title_prompt'));
			$user_conf->_attribute('ai_title_language', Minz_Request::paramString('ai_title_language'));
			$timeout = Minz_Request::paramInt('ai_title_timeout');
			if ($timeout < self::TIMEOUT_MIN || $timeout > self::TIMEOUT_MAX) {
				$timeout = self::TIMEOUT_DEFAULT;
			}
			$user_conf->_attribute('ai_title_timeout', $timeout);
			$user_conf->save();
		}
	}

	/**
	 * Inject a hidden marker carrying the entry id next to the article content.
	 * The marker is picked up by script.js, which automatically requests an
	 * AI-rewritten title and swaps it into the article heading — no button.
	 */
	public function entryBeforeDisplayHook(FreshRSS_Entry $entry): FreshRSS_Entry {
		$entryId = htmlspecialchars((string) $entry->id(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$html = '<div class="ai-title-marker" data-entry-id="' . $entryId . '"></div>';
		$entry->_content($html . $entry->content());
		return $entry;
	}
}
