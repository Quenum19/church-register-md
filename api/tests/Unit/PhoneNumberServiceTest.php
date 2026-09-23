<?php

use App\Enums\PhoneCountry;
use App\Services\PhoneNumberService;

describe('normalize', function (): void {
    it('normalise un numéro valide en E.164', function (string $country, string $raw, string $expected): void {
        expect(PhoneNumberService::normalize($country, $raw))->toBe($expected);
    })->with([
        // Côte d'Ivoire : 10 chiffres depuis 2021, avec ou sans espaces.
        'CI mobile 07 avec espaces' => ['CI', '07 00 00 00 00', '+2250700000000'],
        'CI mobile 07 sans espace' => ['CI', '0700000000', '+2250700000000'],
        'CI mobile 05' => ['CI', '05 12 34 56 78', '+2250512345678'],
        'CI mobile 01' => ['CI', '01 02 03 04 05', '+2250102030405'],
        'CI fixe 27' => ['CI', '27 22 44 55 66', '+2252722445566'],
        'CI avec indicatif +225' => ['CI', '+225 07 00 00 00 00', '+2250700000000'],
        'CI avec préfixe 00225' => ['CI', '00225 0700000000', '+2250700000000'],
        'CI avec tirets et points' => ['CI', '07-00.00-00.00', '+2250700000000'],
        'code pays en minuscules' => ['ci', '0700000000', '+2250700000000'],
        'espaces autour' => ['CI', '  07 00 00 00 00  ', '+2250700000000'],
        'espaces insécables' => ['CI', "07\u{00A0}00\u{00A0}00\u{00A0}00\u{00A0}00", '+2250700000000'],
        // 0 local initial géré par libphonenumber.
        'GH avec 0 local' => ['GH', '024 123 4567', '+233241234567'],
        'GH sans 0 local' => ['GH', '24 123 4567', '+233241234567'],
        'FR avec 0 local' => ['FR', '06 12 34 56 78', '+33612345678'],
        'FR sans 0 local' => ['FR', '6 12 34 56 78', '+33612345678'],
        'BE avec 0 local' => ['BE', '0470 12 34 56', '+32470123456'],
        'US avec parenthèses' => ['US', '(201) 555-0123', '+12015550123'],
        'SN' => ['SN', '77 123 45 67', '+221771234567'],
        'ML' => ['ML', '76 12 34 56', '+22376123456'],
        'BF' => ['BF', '70 12 34 56', '+22670123456'],
        'TG' => ['TG', '90 12 34 56', '+22890123456'],
        'BJ (10 chiffres depuis 2024)' => ['BJ', '01 97 12 34 56', '+2290197123456'],
        'GN' => ['GN', '622 12 34 56', '+224622123456'],
        'CM' => ['CM', '6 71 23 45 67', '+237671234567'],
        'CD avec 0 local' => ['CD', '081 234 5678', '+243812345678'],
        'GA' => ['GA', '077 12 34 56', '+24177123456'],
        // OTHER : numéro international complet.
        'OTHER Royaume-Uni' => ['OTHER', '+44 7400 123456', '+447400123456'],
        'OTHER avec 00' => ['OTHER', '0044 7400 123456', '+447400123456'],
        'OTHER numéro ivoirien' => ['OTHER', '+225 07 00 00 00 00', '+2250700000000'],
    ]);

    it('renvoie null pour un numéro invalide', function (string $country, string $raw): void {
        expect(PhoneNumberService::normalize($country, $raw))->toBeNull();
    })->with([
        'vide' => ['CI', ''],
        'espaces seulement' => ['CI', '   '],
        'CI ancien format 8 chiffres' => ['CI', '07 00 00 00'],
        'CI trop court' => ['CI', '7000000'],
        'CI préfixe inexistant' => ['CI', '08 00 00 00 00'],
        'CI trop long' => ['CI', '07 00 00 00 00 00'],
        'lettres' => ['CI', '07 AB 00 00 00'],
        'extension' => ['CI', '0700000000 ext 12'],
        'numéro étranger pour la CI' => ['CI', '+33 6 12 34 56 78'],
        'BJ ancien format 8 chiffres' => ['BJ', '97 12 34 56'],
        'OTHER sans indicatif' => ['OTHER', '07 00 00 00 00'],
        'OTHER indicatif inconnu' => ['OTHER', '+999 123 456 789'],
        'pays inconnu' => ['ZZ', '+2250700000000'],
        'pays non accepté' => ['DE', '0151 23456789'],
        'saisie démesurée' => ['CI', str_repeat('0', 40)],
        'retour à la ligne' => ['CI', "07000000\n00"],
        'tabulation' => ['CI', "0700\t000000"],
    ]);

    it('accepte tous les pays du contrat', function (): void {
        expect(PhoneCountry::values())->toBe([
            'CI', 'SN', 'ML', 'BF', 'GH', 'TG', 'BJ', 'GN', 'CM', 'CD', 'GA', 'FR', 'BE', 'US', 'OTHER',
        ]);
    });
});

describe('hash', function (): void {
    it('produit un HMAC stable et différent selon le numéro', function (): void {
        $a = PhoneNumberService::hash('+2250700000000');

        expect($a)->toMatch('/^[a-f0-9]{64}$/')
            ->and(PhoneNumberService::hash('+2250700000000'))->toBe($a)
            ->and(PhoneNumberService::hash('+2250700000001'))->not->toBe($a)
            ->and($a)->not->toBe(hash('sha256', '+2250700000000'));
    });

    it('dépend de APP_KEY', function (): void {
        $before = PhoneNumberService::hash('+2250700000000');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        expect(PhoneNumberService::hash('+2250700000000'))->not->toBe($before);
    });

    it('refuse de hacher sans APP_KEY', function (): void {
        config(['app.key' => '']);

        PhoneNumberService::hash('+2250700000000');
    })->throws(RuntimeException::class);
});

it('formate un numéro E.164 pour l\'affichage', function (): void {
    expect(PhoneNumberService::formatInternational('+2250700000000'))->toBe('+225 07 00 00 0000')
        ->and(PhoneNumberService::formatInternational('invalide'))->toBe('invalide');
});
