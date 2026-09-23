<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Paramètres initiaux. Idempotent : une valeur déjà enregistrée n'est jamais écrasée.
 */
class SettingsSeeder extends Seeder
{
    public function run(SettingsService $settings): void
    {
        $defaults = [
            'church_name' => SettingsService::DEFAULT_CHURCH_NAME,
            'public_url' => (string) config('app.url'),
            'verse' => ['preset' => 0],
            'verse_presets' => SettingsService::VERSE_PRESETS,
        ];

        foreach ($defaults as $key => $value) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }

        $settings->flush();
    }
}
