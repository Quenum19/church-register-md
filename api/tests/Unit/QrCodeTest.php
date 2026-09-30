<?php

use App\Support\QrCode;
use App\Support\QrPng;

/*
| Générateur de QR code écrit à la main (aucune bibliothèque PHP disponible, et la consigne
| interdit d'en ajouter). Les vecteurs de référence de tests/Fixtures/qr-vectors.json ont été
| produits par la bibliothèque `qrcode` (npm) — CELLE QU'UTILISE LE SPA — en segment unique,
| mode octet, correction d'erreur M. On compare la matrice ENTIÈRE : version, masque retenu et
| chaque module. Une erreur dans la table de correction, le corps de Galois, l'entrelacement,
| le placement ou les pénalités de masquage fait échouer la comparaison.
|
| Régénérer les vecteurs (jamais à la main), depuis la racine du dépôt :
|   node api/tests/Fixtures/qr-vectors.cjs > api/tests/Fixtures/qr-vectors.json
*/

/**
 * @return array{matrices: list<array{text: string, version: int, size: int, mask: int, rows: list<string>}>, versions: list<array{length: int, version: int}>}
 */
function qrVectors(): array
{
    /** @var array{matrices: list<array{text: string, version: int, size: int, mask: int, rows: list<string>}>, versions: list<array{length: int, version: int}>} $data */
    $data = json_decode((string) file_get_contents(base_path('tests/Fixtures/qr-vectors.json')), true, 512, JSON_THROW_ON_ERROR);

    return $data;
}

/**
 * Matrice rendue en lignes de « 0 » et « 1 », comme dans les vecteurs.
 *
 * @return list<string>
 */
function qrRows(QrCode $code): array
{
    return array_map(
        static fn (array $line): string => implode('', array_map(static fn (bool $dark): string => $dark ? '1' : '0', $line)),
        $code->matrix(),
    );
}

it('reproduit exactement les matrices de la bibliothèque du SPA', function (): void {
    $vectors = qrVectors()['matrices'];

    expect($vectors)->not->toBeEmpty();

    foreach ($vectors as $vector) {
        $code = QrCode::encode($vector['text']);
        $label = sprintf('%d octets (version %d attendue)', strlen($vector['text']), $vector['version']);

        expect($code->version)->toBe($vector['version'], $label)
            ->and($code->size())->toBe($vector['size'], $label)
            ->and($code->mask)->toBe($vector['mask'], $label)
            ->and(qrRows($code))->toBe($vector['rows'], $label);
    }
});

it('choisit la plus petite version qui contient le texte', function (): void {
    foreach (qrVectors()['versions'] as $vector) {
        $code = QrCode::encode(str_repeat('a', $vector['length']));

        expect($code->version)->toBe(
            $vector['version'],
            sprintf('%d octets devraient tenir en version %d', $vector['length'], $vector['version']),
        );
    }
});

it('place les motifs fonctionnels imposés par la norme', function (): void {
    $code = QrCode::encode('https://registre.newinechurch.org/e/culte-4-octobre');
    $size = $code->size();

    // Motifs de repérage : anneau sombre de 7 × 7 avec un cœur plein de 3 × 3, aux trois coins.
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
        foreach (range(0, 6) as $row) {
            foreach (range(0, 6) as $column) {
                $ring = max(abs($row - 3), abs($column - 3));
                expect($code->isDark($top + $row, $left + $column))->toBe($ring !== 2);
            }
        }
    }

    // Motifs de synchronisation : alternance sur la ligne et la colonne 6.
    for ($i = 8; $i < $size - 8; $i++) {
        expect($code->isDark(6, $i))->toBe($i % 2 === 0)
            ->and($code->isDark($i, 6))->toBe($i % 2 === 0);
    }

    // Module toujours sombre, sous le repère supérieur gauche.
    expect($code->isDark($size - 8, 8))->toBeTrue();

    // Séparateurs clairs autour du repère supérieur gauche.
    foreach (range(0, 7) as $i) {
        expect($code->isDark(7, $i))->toBeFalse()
            ->and($code->isDark($i, 7))->toBeFalse();
    }
});

it('refuse un texte au-delà de la version 20', function (): void {
    expect(fn () => QrCode::encode(str_repeat('a', 667)))
        ->toThrow(RuntimeException::class, 'Texte trop long');
});

it('rend un PNG noir et blanc avec la marge claire imposée', function (): void {
    $code = QrCode::encode('https://registre.newinechurch.org/e/culte-4-octobre');
    $png = QrPng::png($code);

    $size = getimagesizefromstring($png);
    $expected = ($code->size() + 2 * QrPng::QUIET_ZONE) * QrPng::MODULE_PIXELS;

    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe($expected)
        ->and($size[1])->toBe($expected)
        ->and($size[2])->toBe(IMAGETYPE_PNG)
        // Une image de deux couleurs reste minuscule : elle voyage en data URI dans le PDF.
        ->and(strlen($png))->toBeLessThan(4096);

    $image = imagecreatefromstring($png);
    expect($image)->not->toBeFalse();

    $colour = static function (int $x, int $y) use ($image): array {
        $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return [$rgb['red'], $rgb['green'], $rgb['blue']];
    };

    // Coin dans la marge : blanc. Premier module du repère supérieur gauche : noir.
    $quiet = QrPng::QUIET_ZONE * QrPng::MODULE_PIXELS;
    expect($colour(1, 1))->toBe([255, 255, 255])
        ->and($colour($quiet + 1, $quiet + 1))->toBe([0, 0, 0]);

    expect(QrPng::dataUri($code))->toStartWith('data:image/png;base64,')
        ->and(base64_decode(substr(QrPng::dataUri($code), strlen('data:image/png;base64,')), true))->toBe($png);
});
