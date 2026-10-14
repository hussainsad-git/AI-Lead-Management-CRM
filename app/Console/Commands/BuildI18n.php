<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** lang/<locale>.json is the single source of truth; this writes the browser copies under public/js/i18n. */
class BuildI18n extends Command
{
    protected $signature = 'i18n:build {--check : Fail if the generated files would change (CI)}';

    protected $description = 'Generate public/js/i18n/*.js from lang/*.json';

    public function handle(): int
    {
        $changed = 0;
        foreach (array_keys(config('locales')) as $loc) {
            $src = base_path("lang/$loc.json");
            $strings = $loc === 'en' || ! file_exists($src) ? [] : json_decode(file_get_contents($src), true, 512, JSON_THROW_ON_ERROR);
            $js = 'window.LF_I18N = '.json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).";\n";
            $dest = public_path("js/i18n/$loc.js");
            if (! file_exists($dest) || file_get_contents($dest) !== $js) {
                $changed++;
                if (! $this->option('check')) {
                    file_put_contents($dest, $js);
                }
            }
            $this->line(sprintf('%-4s %5d strings', $loc, count($strings)));
        }
        if ($this->option('check') && $changed) {
            $this->error("$changed i18n file(s) are out of date — run php artisan i18n:build");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
