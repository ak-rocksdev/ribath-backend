<?php

namespace App\Support;

/**
 * Process environment the PDF exports (spatie/laravel-pdf → Browsershot →
 * puppeteer) need before rendering. Shared by every controller that
 * renders a PDF so the PHP-FPM workaround lives in one place.
 */
final class BrowsershotEnvironment
{
    /**
     * Tells puppeteer's resolver where to find its bundled Chromium cache
     * (config services.browsershot.puppeteer_cache_dir; a no-op when unset).
     *
     * It has to be set on the PHP process env (not on Browsershot's
     * launch-options env, which only affects the child Chrome process)
     * because puppeteer reads it from process.env when *resolving the binary
     * path* before launch.
     *
     * PHP-FPM commonly has variables_order="GPCS" (no E), so $_ENV is empty
     * and Symfony Process's getDefaultEnv() ends up not propagating putenv()
     * values to the child Node subprocess reliably. Setting all three
     * guarantees the env reaches puppeteer.
     */
    public static function preparePuppeteerCache(): void
    {
        $cacheDir = config('services.browsershot.puppeteer_cache_dir');

        if (! $cacheDir) {
            return;
        }

        putenv("PUPPETEER_CACHE_DIR={$cacheDir}");
        $_ENV['PUPPETEER_CACHE_DIR'] = $cacheDir;
        $_SERVER['PUPPETEER_CACHE_DIR'] = $cacheDir;
    }
}
