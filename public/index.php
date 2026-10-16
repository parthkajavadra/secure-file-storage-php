<?php

use App\Kernel;

// Settings have defaults in config/services.yaml; a .env file is optional.
if (!is_file(dirname(__DIR__).'/.env') && !is_string($_SERVER['APP_RUNTIME_OPTIONS'] ?? null)) {
    $_SERVER['APP_RUNTIME_OPTIONS']['disable_dotenv'] = true;
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
