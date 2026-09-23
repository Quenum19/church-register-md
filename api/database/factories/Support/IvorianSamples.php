<?php

namespace Database\Factories\Support;

use App\Services\PhoneNumberService;

/**
 * Données fictives réalistes (noms ivoiriens, communes et quartiers d'Abidjan)
 * pour les factories et le DemoSeeder. Aucune personne réelle.
 */
final class IvorianSamples
{
    /** @var list<string> */
    public const FIRST_NAMES = [
        'Kouassi', 'Koffi', 'Konan', 'Yao', 'Kouamé', 'Aya', 'Adjoua', 'Amenan', 'Akissi', 'Affoué',
        'Aminata', 'Mariam', 'Fatou', 'Drissa', 'Seydou', 'Ibrahim', 'Moussa', 'Awa', 'Bintou', 'Christelle',
        'Grâce', 'Esther', 'Emmanuel', 'Jean-Marc', 'Serge', 'Hervé', 'Arnaud', 'Patricia', 'Nadège', 'Josiane',
        'Olivier', 'Didier', 'Wilfried', 'Rébecca', 'Priscille', 'Ange', 'Désiré', 'Innocent', 'Béatrice', 'Marie-Laure',
    ];

    /** @var list<string> */
    public const LAST_NAMES = [
        'Kouassi', 'Koffi', 'Konan', 'Yao', "N'Guessan", 'Kouadio', 'Kouamé', 'Bamba', 'Traoré', 'Coulibaly',
        'Ouattara', 'Koné', 'Diabaté', 'Touré', 'Cissé', 'Diomandé', 'Aka', 'Assi', 'Brou', 'Ehui',
        'Gnagne', 'Kacou', 'Kra', "N'Dri", 'Tanoh', 'Yapi', 'Zadi', 'Dosso', 'Fofana', 'Sanogo', 'Soro', 'Tapé', 'Séry',
    ];

    /** @var array<string, list<string>> */
    public const COMMUNES = [
        'Cocody' => ['Angré', 'Riviera 2', 'Riviera Palmeraie', 'Deux-Plateaux', 'Blockhaus', 'Faya'],
        'Yopougon' => ['Niangon', 'Sicogi', 'Selmer', 'Maroc', 'Toits Rouges', 'Andokoi'],
        'Abobo' => ['Avocatier', 'PK 18', 'Abobo Baoulé', 'Anonkoua-Kouté', 'Sagbé'],
        'Marcory' => ['Zone 4', 'Biétry', 'Anoumabo', 'Marcory Résidentiel'],
        'Koumassi' => ['Remblais', 'Sicogi', 'Grand Campement', 'Divo'],
        'Treichville' => ['Arras', 'Biafra', 'Belleville'],
        'Adjamé' => ['Williamsville', '220 Logements', 'Bracodi'],
        'Port-Bouët' => ['Vridi', 'Gonzagueville', 'Adjouffou'],
        'Plateau' => ['Plateau Centre', 'Indénié'],
        'Attécoubé' => ['Santé', 'Locodjro', 'Agban'],
        'Bingerville' => ['Feh Kessé', 'Akouai-Santai'],
        'Anyama' => ['Anyama Centre', 'Ebimpé'],
    ];

    public static function fullName(): string
    {
        return fake()->randomElement(self::FIRST_NAMES).' '.fake()->randomElement(self::LAST_NAMES);
    }

    /**
     * @return array{0: string, 1: string} [commune, quartier]
     */
    public static function place(): array
    {
        $commune = fake()->randomElement(array_keys(self::COMMUNES));

        return [$commune, fake()->randomElement(self::COMMUNES[$commune])];
    }

    /**
     * Numéro mobile ivoirien unique au format E.164 (+225 01/05/07 + 8 chiffres).
     */
    public static function mobileE164(): string
    {
        do {
            $national = fake()->unique()->numerify(fake()->randomElement(['01', '05', '07']).'########');
            $e164 = PhoneNumberService::normalize('CI', $national);
        } while ($e164 === null);

        return $e164;
    }
}
