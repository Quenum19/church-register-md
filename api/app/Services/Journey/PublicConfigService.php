<?php

namespace App\Services\Journey;

use App\Models\Family;
use App\Services\FamilyRotationService;
use App\Services\SettingsService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Configuration publique (GET /api/public/config, contrat §2), en cache 60 secondes.
 *
 * La clé de cache `public:config` est vidée par l'API admin quand les paramètres ou les
 * rotations changent : ne pas la renommer.
 */
class PublicConfigService
{
    public const CACHE_KEY = 'public:config';

    public const TTL_SECONDS = 60;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SettingsService $settings,
        private readonly FamilyRotationService $rotations,
    ) {}

    /**
     * @return array{church_name: string, public_url: string, verse: array{ref: string, text: string}, current_family: array{id: int, name: string}|null, families: list<array{id: int, name: string}>}
     */
    public function get(): array
    {
        /** @var array{church_name: string, public_url: string, verse: array{ref: string, text: string}, current_family: array{id: int, name: string}|null, families: list<array{id: int, name: string}>} */
        return $this->cache->remember(self::CACHE_KEY, self::TTL_SECONDS, fn (): array => $this->build());
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return array{church_name: string, public_url: string, verse: array{ref: string, text: string}, current_family: array{id: int, name: string}|null, families: list<array{id: int, name: string}>}
     */
    private function build(): array
    {
        $verse = $this->settings->verse();
        $current = $this->rotations->current();

        $families = Family::query()
            ->active()
            ->ordered()
            ->get(['id', 'name'])
            ->map(fn (Family $family): array => $this->familyRef($family))
            ->values()
            ->all();

        return [
            'church_name' => $this->settings->churchName(),
            'public_url' => $this->settings->publicUrl(),
            'verse' => ['ref' => $verse['ref'], 'text' => $verse['text']],
            'current_family' => $current === null ? null : $this->familyRef($current),
            'families' => $families,
        ];
    }

    /**
     * @return array{id: int, name: string}
     */
    private function familyRef(Family $family): array
    {
        return ['id' => $family->id, 'name' => $family->name];
    }
}
