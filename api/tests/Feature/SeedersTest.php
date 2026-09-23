<?php

use App\Enums\VisitorStatus;
use App\Models\Family;
use App\Models\FamilyRotation;
use App\Models\Member;
use App\Models\Setting;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\SettingsService;
use App\Services\VisitorStatusService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;

describe('DatabaseSeeder', function (): void {
    beforeEach(fn () => $this->seed(DatabaseSeeder::class));

    it('crée les 7 familles dans l\'ordre de rotation', function (): void {
        expect(Family::query()->ordered()->pluck('name')->all())
            ->toBe(['Puissance', 'Richesse', 'Sagesse', 'Force', 'Honneur', 'Gloire', 'Louange'])
            ->and(Family::query()->ordered()->pluck('position')->all())->toBe([1, 2, 3, 4, 5, 6, 7]);
    });

    it('crée une rotation par mois de 2025-11 à 2036-12', function (): void {
        $first = FamilyRotation::query()->with('family')->orderBy('year')->orderBy('month')->firstOrFail();
        $last = FamilyRotation::query()->with('family')->orderByDesc('year')->orderByDesc('month')->firstOrFail();
        $at = fn (int $year, int $month): ?string => FamilyRotation::query()
            ->with('family')->where(['year' => $year, 'month' => $month])->first()?->family->name;

        expect(FamilyRotation::query()->count())->toBe(134)
            ->and([$first->year, $first->month, $first->family->name])->toBe([2025, 11, 'Puissance'])
            ->and([$last->year, $last->month])->toBe([2036, 12])
            ->and($at(2026, 2))->toBe('Force')
            ->and($at(2026, 9))->toBe('Force');
    });

    it('enregistre les paramètres par défaut', function (): void {
        $settings = app(SettingsService::class)->toArray();

        expect($settings['church_name'])->toBe('Église La Maison de la Destinée')
            ->and($settings['public_url'])->toBe(config('app.url'))
            ->and($settings['verse'])->toBe(['preset' => 0, 'ref' => 'Jean 21:17', 'text' => "Si tu m'aimes, pais mes brebis."])
            ->and($settings['verse_presets'])->toHaveCount(3)
            ->and(Setting::query()->count())->toBe(4);
    });

    it('ne crée aucun utilisateur', function (): void {
        expect(User::query()->count())->toBe(0);
    });

    it('est relançable sans doublon ni écrasement des modifications', function (): void {
        app(SettingsService::class)->set('church_name', 'Nom modifié par un admin');
        $gloire = Family::query()->where('name', 'Gloire')->firstOrFail();
        FamilyRotation::query()->where(['year' => 2026, 'month' => 10])->update(['family_id' => $gloire->id]);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        expect(Family::query()->count())->toBe(7)
            ->and(FamilyRotation::query()->count())->toBe(134)
            ->and(Setting::query()->count())->toBe(4)
            ->and(app(SettingsService::class)->churchName())->toBe('Nom modifié par un admin')
            ->and(FamilyRotation::query()->where(['year' => 2026, 'month' => 10])->value('family_id'))->toBe($gloire->id);
    });
});

describe('DemoSeeder', function (): void {
    it('crée des visiteurs cohérents', function (): void {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        $visitors = Visitor::query()->withCount('visits')->with('member')->get();

        expect($visitors)->toHaveCount(DemoSeeder::VISITORS)
            ->and(Member::query()->count())->toBeGreaterThan(0)
            ->and(User::query()->count())->toBe(0)
            ->and(Visit::query()->whereNull('family_id')->count())->toBe(0)
            ->and(Visit::query()->where('visit_date', '>', now()->toDateString())->count())->toBe(0);

        foreach ($visitors as $visitor) {
            $expected = $visitor->member !== null
                ? VisitorStatus::Membre
                : VisitorStatus::fromVisitCount($visitor->visits_count);

            expect($visitor->status)->toBe($expected)
                ->and($visitor->visits_count)->toBeBetween(1, 3)
                ->and($visitor->phone)->toStartWith('+225');
        }
    });

    it('refuse de s\'exécuter en production', function (): void {
        app()->detectEnvironment(fn (): string => 'production');

        expect(fn () => (new DemoSeeder)->run(app(VisitorStatusService::class)))
            ->toThrow(RuntimeException::class);
    });
});
