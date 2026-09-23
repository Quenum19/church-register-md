<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Paramètres applicatifs (table `settings`), avec cache et valeurs par défaut.
 *
 * Clés connues : church_name, public_url, verse, verse_presets.
 * `verse` est stocké sous la forme { "preset": 0|1|2 } ou { "preset": null, "ref": "…", "text": "…" }.
 */
class SettingsService
{
    public const CACHE_KEY = 'settings.all';

    public const DEFAULT_CHURCH_NAME = 'Église La Maison de la Destinée';

    /**
     * Versets prédéfinis du contrat d'API (§4, Paramètres).
     *
     * @var list<array{ref: string, text: string}>
     */
    public const VERSE_PRESETS = [
        ['ref' => 'Jean 21:17', 'text' => "Si tu m'aimes, pais mes brebis."],
        ['ref' => 'Jean 3:16', 'text' => "Car Dieu a tant aimé le monde qu'il a donné son Fils unique, afin que quiconque croit en lui ne périsse point, mais qu'il ait la vie éternelle."],
        ['ref' => 'Psaumes 23:1', 'text' => "L'Éternel est mon berger : je ne manquerai de rien."],
    ];

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Valeurs par défaut, utilisées quand une clé n'est pas (ou mal) enregistrée.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'church_name' => self::DEFAULT_CHURCH_NAME,
            'public_url' => (string) config('app.url'),
            'verse' => ['preset' => 0],
            'verse_presets' => self::VERSE_PRESETS,
        ];
    }

    /**
     * Toutes les valeurs enregistrées, fusionnées avec les valeurs par défaut.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace($this->defaults(), $this->stored());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stored = $this->stored();

        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        return $this->defaults()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * Écrit plusieurs clés puis invalide le cache.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->flush();
    }

    /**
     * Invalide le cache des paramètres.
     */
    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    public function churchName(): string
    {
        $value = $this->get('church_name');

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_CHURCH_NAME;
    }

    public function publicUrl(): string
    {
        $value = $this->get('public_url');

        return is_string($value) && $value !== '' ? $value : (string) config('app.url');
    }

    /**
     * @return list<array{ref: string, text: string}>
     */
    public function versePresets(): array
    {
        $value = $this->get('verse_presets');

        if (! is_array($value) || $value === []) {
            return self::VERSE_PRESETS;
        }

        $presets = [];

        foreach ($value as $preset) {
            if (is_array($preset) && is_string($preset['ref'] ?? null) && is_string($preset['text'] ?? null)) {
                $presets[] = ['ref' => $preset['ref'], 'text' => $preset['text']];
            }
        }

        return $presets === [] ? self::VERSE_PRESETS : $presets;
    }

    /**
     * Verset affiché, résolu : preset (index dans versePresets()) ou verset personnalisé (preset null).
     *
     * @return array{preset: int|null, ref: string, text: string}
     */
    public function verse(): array
    {
        $presets = $this->versePresets();
        $value = $this->get('verse');

        if (is_array($value)) {
            $preset = $value['preset'] ?? null;

            if (is_int($preset) && isset($presets[$preset])) {
                return ['preset' => $preset, ...$presets[$preset]];
            }

            if ($preset === null && is_string($value['ref'] ?? null) && is_string($value['text'] ?? null)) {
                return ['preset' => null, 'ref' => $value['ref'], 'text' => $value['text']];
            }
        }

        return ['preset' => 0, ...$presets[0]];
    }

    /**
     * Choisit un verset prédéfini (`$preset` = index) ou personnalisé (`$preset` null + ref + texte).
     */
    public function setVerse(?int $preset, ?string $ref = null, ?string $text = null): void
    {
        $this->set('verse', $preset !== null
            ? ['preset' => $preset]
            : ['preset' => null, 'ref' => (string) $ref, 'text' => (string) $text]);
    }

    /**
     * Représentation du contrat : GET /api/admin/settings.
     *
     * @return array{church_name: string, public_url: string, verse: array{preset: int|null, ref: string, text: string}, verse_presets: list<array{ref: string, text: string}>}
     */
    public function toArray(): array
    {
        return [
            'church_name' => $this->churchName(),
            'public_url' => $this->publicUrl(),
            'verse' => $this->verse(),
            'verse_presets' => $this->versePresets(),
        ];
    }

    /**
     * Valeurs enregistrées en base (mises en cache sans expiration, invalidées à l'écriture).
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        /** @var array<string, mixed> */
        return $this->cache->rememberForever(self::CACHE_KEY, static function (): array {
            $values = [];

            foreach (Setting::query()->get() as $setting) {
                $values[$setting->key] = $setting->value;
            }

            return $values;
        });
    }
}
