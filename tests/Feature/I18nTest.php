<?php

namespace Tests\Feature;

use App\Models\AudienceSegment;
use App\Models\AutomationRule;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class I18nTest extends TestCase
{
    public function test_every_ui_string_has_urdu_and_arabic_and_js_bundles_are_in_sync(): void
    {
        $strings = [];
        foreach (array_merge(glob(resource_path('views/**/*.blade.php')), glob(resource_path('views/*/*/*.blade.php')) ?: [], glob(public_path('js/pages/*.js')), [public_path('js/core.js')]) as $f) {
            $src = file_get_contents($f);
            preg_match_all("/(?:__|\\bt|LF\\.t)\\('((?:[^'\\\\]|\\\\.)*)'\\)/", $src, $m);
            foreach ($m[1] as $s) {
                $strings[str_replace("\\'", "'", $s)] = true;
            }
        }
        $this->assertGreaterThan(500, count($strings));
        foreach (['ur', 'ar'] as $loc) {
            $json = json_decode(file_get_contents(base_path("lang/$loc.json")), true);
            $missing = array_diff_key($strings, $json);
            $this->assertSame([], array_keys($missing), "Untranslated $loc strings");
            foreach ($json as $en => $tr) { // placeholders survive translation
                preg_match_all('/\{\w+\}|:\w+/', $en, $a);
                preg_match_all('/\{\w+\}|:\w+/', $tr, $b);
                $this->assertEqualsCanonicalizing($a[0], $b[0], "Placeholder mismatch in $loc: $en");
            }
        }
        $this->assertSame(0, Artisan::call('i18n:build', ['--check' => true]), 'public/js/i18n is out of date: run php artisan i18n:build');
    }

    public function test_seeded_rules_and_segments_use_the_business_language(): void
    {
        $org = app(OrganizationProvisioner::class)->create('Urdu Co', 'Asia/Karachi', 'PKR', 'PK');
        $org->forceFill(['default_locale' => 'ur'])->save();
        $this->inOrg($org, function () {
            AutomationRule::query()->delete();
            AudienceSegment::query()->delete();
        });
        app(OrganizationProvisioner::class)->provision($org->fresh());
        $this->inOrg($org, function () {
            $this->assertSame('پہلے رابطے کا SLA چھوٹ گیا', AutomationRule::where('template_key', 'sla_uncontacted')->value('name'));
            $this->assertStringContainsString('30 منٹ', AutomationRule::where('template_key', 'sla_uncontacted')->value('description'));
            $this->assertSame('کنورٹ شدہ گاہک', AudienceSegment::where('template_key', 'customers')->value('name'));
        });
        $this->assertSame('en', app()->getLocale());
    }
}
