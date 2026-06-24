<?php

declare(strict_types=1);

define('AI_TITLE_TESTING', true);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs/FreshRSS.php';
require_once __DIR__ . '/../extension.php';
require_once __DIR__ . '/../AiTitleService.php';

FreshRSS_Context::init();
