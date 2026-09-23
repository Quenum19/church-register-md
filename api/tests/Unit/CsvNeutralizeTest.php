<?php

use App\Exports\CsvVisitorExport;

/*
|--------------------------------------------------------------------------
| Injection de formule CSV (revue de sécurité, point 5)
|--------------------------------------------------------------------------
|
| La neutralisation ne testait que le PREMIER octet de la cellule. Un tableur, lui, ignore
| les blancs et caractères invisibles de tête : «  =1+1 » ou « \u{00A0}=cmd… » restaient donc
| interprétés comme des formules. Le premier caractère SIGNIFICATIF décide désormais.
|
*/

it('neutralise une formule même précédée de blancs ou de caractères invisibles', function (string $value): void {
    expect(CsvVisitorExport::neutralize($value))->toBe("'".$value);
})->with([
    'égal' => ['=1+1'],
    'plus' => ['+SUM(1,2)'],
    'moins' => ['-2+3'],
    'arobase' => ['@IMPORTXML("x")'],
    'tabulation' => ["\tTabulation"],
    'retour chariot' => ["\rRetour"],
    'espace simple' => ['  =1+1'],
    'espace insécable' => ["\u{00A0}=cmd|' /C calc'!A0"],
    'espace de largeur nulle' => ["\u{200B}@IMPORTXML(\"x\")"],
    'BOM' => ["\u{FEFF}=HYPERLINK(\"http://x\")"],
    'marque de direction' => ["\u{200E}-2+3"],
    'blancs mélangés' => [" \t\u{00A0} +SUM(1,2)"],
    'saut de ligne' => ["\n=1+1"],
]);

it('laisse intacte une valeur ordinaire', function (string $value): void {
    expect(CsvVisitorExport::neutralize($value))->toBe($value);
})->with([
    'vide' => [''],
    'nom' => ['Aya Kouassi'],
    'égal au milieu' => ['Normal = ok'],
    'espaces puis texte' => ['   Cocody'],
    'espace insécable puis texte' => ["\u{00A0}Angré"],
    'accent en tête' => ['Émile'],
]);

it('neutralise un numéro de téléphone commençant par « + » (colonne export)', function (): void {
    expect(CsvVisitorExport::neutralize('+225 07 00 00 0001'))->toBe("'+225 07 00 00 0001");
});

it('ne casse pas une chaîne qui n\'est pas de l\'UTF-8 valide', function (): void {
    $invalid = "\xC3\x28=1+1";

    expect(CsvVisitorExport::neutralize($invalid))->toBe($invalid)
        ->and(CsvVisitorExport::neutralize("=\xC3\x28"))->toBe("'=\xC3\x28");
});
