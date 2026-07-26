<?php

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Test Environment Normalization
|--------------------------------------------------------------------------
|
| The `<env>` entries in phpunit.xml are written to putenv() and $_ENV, but not
| to $_SERVER. When the shell already exports the values from `.env` (a common
| local setup, and how CLI PHP populates $_SERVER), Laravel keeps reading the
| development values because `Illuminate\Support\Env` resolves $_SERVER before
| $_ENV. That silently ran the suite with APP_ENV=local (CSRF verification stays
| enabled, so every POST test fails with 419) and against the development
| database instead of sqlite `:memory:`.
|
| Aligning $_SERVER with the values PHPUnit just set makes the test environment
| authoritative regardless of what the surrounding shell exports.
|
*/

foreach ($_ENV as $key => $value) {
    if (($_SERVER[$key] ?? null) !== $value) {
        $_SERVER[$key] = $value;
    }
}
