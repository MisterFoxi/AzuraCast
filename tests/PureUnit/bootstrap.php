<?php

declare(strict_types=1);

$autoloader = require __DIR__ . '/../../vendor/autoload.php';

if (!function_exists('__')) {
    Gettext\TranslatorFunctions::register(new Gettext\Translator());
}
