<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Captcha storage isolation
|--------------------------------------------------------------------------
|
| The captcha monitor + Node sidecar treat storage/app/captcha (ivac-bundle.js,
| encrypt_meta.json, the versioned bundle store) as live encryption state. Some
| feature tests exercise the real analyze()/activate() path, which writes there —
| so without isolation a test run silently clobbers the developer's live captcha
| config (a stale meta there makes production captcha 400). Point every feature
| test at a throwaway temp directory and remove it afterwards.
|
*/
pest()->beforeEach(function (): void {
    $dir = sys_get_temp_dir().'/ipms_captcha_test_'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);
    config(['captcha.storage_path' => $dir]);
    $this->captchaTestDir = $dir;
})->afterEach(function (): void {
    if (isset($this->captchaTestDir) && is_dir($this->captchaTestDir)) {
        Illuminate\Support\Facades\File::deleteDirectory($this->captchaTestDir);
    }
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
