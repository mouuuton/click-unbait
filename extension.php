<?php

declare(strict_types=1);

require_once __DIR__ . '/AiTitleService.php';

final class AiTitleExtension extends Minz_Extension {

	public const TIMEOUT_MIN = 1;
	public const TIMEOUT_MAX = 300;
	public const TIMEOUT_DEFAULT = 30;

	#[\Override]
	public function init(): void {
		$this->registerTranslates();
		// Rewrite the title server-side, before the entry is saved, so the new
		// title is persisted and served to every client (web UI, NetNewsWire,
		// official apps, …) via the sync API — not just the browser.
		$this->registerHook('entry_before_insert', [$this, 'entryBeforeInsertHook']);
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
	 * Runs during feed refresh, before each new entry is inserted. Replaces the
	 * title with an AI-rewritten, de-clickbaited version. Failures are swallowed
	 * so article ingestion is never blocked.
	 */
	public function entryBeforeInsertHook(FreshRSS_Entry $entry): FreshRSS_Entry {
		try {
			$cfg = AiTitleService::readConfig(FreshRSS_Context::userConf());
			if (!AiTitleService::isConfigured($cfg)) {
				return $entry;
			}
			$service = new AiTitleService();
			$newTitle = $service->rewriteTitle($entry->title(), $entry->content(), $cfg);
			if ($newTitle !== null && $newTitle !== '' && $newTitle !== $entry->title()) {
				$entry->_title($newTitle);
			}
		} catch (\Throwable $e) {
			// Never block ingestion on an AI failure.
		}
		return $entry;
	}
}
