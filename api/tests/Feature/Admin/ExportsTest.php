<?php

use App\Enums\Source;
use App\Exceptions\ApiException;
use App\Exports\ExportLogo;
use App\Exports\PdfVisitorExport;
use App\Exports\VisitorExport;
use App\Exports\XlsxVisitorExport;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Visitor;
use App\Queries\VisitorListQuery;
use App\Services\SettingsService;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Support\AdminFixtures;

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    $this->families = AdminFixtures::families();
    $this->actingAs(User::factory()->superAdmin()->create());
});

/**
 * Lignes d'un CSV exporté (BOM retiré), chaque ligne découpée selon le séparateur `;`.
 *
 * @return list<list<string>>
 */
function exportCsvRows(TestResponse $response): array
{
    $content = $response->streamedContent();
    $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;

    return array_values(array_map(
        static fn (string $line): array => str_getcsv($line, ';', '"', ''),
        array_filter(explode("\r\n", $content), static fn (string $line): bool => $line !== ''),
    ));
}

/**
 * Contenu XML d'une entrée de l'archive XLSX exportée.
 */
function exportXlsxEntry(TestResponse $response, string $entry): string|false
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $response->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue('archive ZIP invalide');
    $xml = $zip->getFromName($entry);
    $zip->close();
    unlink($path);

    return $xml;
}

/**
 * Valeurs (texte décodé) des cellules de la 1re feuille XLSX, indexées par NUMÉRO DE LIGNE
 * (1-indexé, comme dans Excel) puis par colonne (A = 0). Les lignes vides sont absentes.
 *
 * @return array<int, array<int, string>>
 */
function exportXlsxRows(string $sheetXml): array
{
    $dom = new DOMDocument;
    $dom->loadXML($sheetXml);
    $rows = [];

    foreach ($dom->getElementsByTagName('row') as $row) {
        $cells = [];

        foreach ($row->getElementsByTagName('c') as $cell) {
            $column = preg_replace('/\d+/', '', $cell->getAttribute('r')) ?? '';
            $index = array_sum(array_map(
                static fn (int $i, string $letter): int => (ord($letter) - 64) * 26 ** $i,
                array_keys(str_split(strrev($column))),
                str_split(strrev($column)),
            )) - 1;
            $cells[$index] = $cell->textContent;
        }

        $rows[(int) $row->getAttribute('r')] = $cells;
    }

    return $rows;
}

/**
 * Texte de chaque page d'un PDF dompdf, dans l'ordre du document : on suit le /Contents de
 * chaque objet /Type /Page (il ne faut pas parcourir tous les flux : les fontes et le logo en
 * contiennent aussi), on décompresse (zlib) et on décode les chaînes UTF-16BE des opérateurs TJ.
 *
 * @return list<string>
 */
function exportPdfPages(string $pdf): array
{
    preg_match_all('/\/Type \/Page[^s].{0,400}?\/Contents (\d+) 0 R/s', $pdf, $references);
    $pages = [];

    foreach ($references[1] as $id) {
        if (preg_match('/(?:^|\n)'.$id.' 0 obj\b.{0,400}?stream\r?\n(.*?)endstream/s', $pdf, $object) !== 1) {
            continue;
        }

        $content = @gzuncompress($object[1]);

        if ($content === false) {
            continue;
        }

        preg_match_all('/\[\((.*?)\)\]\s*TJ/s', $content, $runs);
        $pages[] = implode("\n", array_map(
            static fn (string $run): string => (string) mb_convert_encoding(stripcslashes($run), 'UTF-8', 'UTF-16BE'),
            $runs[1],
        ));
    }

    return $pages;
}

describe('CSV', function (): void {
    it('produit un CSV UTF-8 avec BOM, séparateur ; et en-têtes français', function (): void {
        AdminFixtures::visitor(['2026-08-02', '2026-09-06'], [
            'full_name' => 'Awa Koné',
            'phone' => '+2250700000001',
            'whatsapp' => '+33612345678',
            'commune' => 'Cocody',
            'quartier' => 'Angré',
            'source' => Source::InviteMembre,
            'invited_by' => 'Marie K.',
        ]);

        $response = $this->get('/api/admin/exports/visitors.csv')->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('visiteurs-2026-09-22-1000.csv');

        $content = $response->streamedContent();
        expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF")
            ->and(mb_check_encoding($content, 'UTF-8'))->toBeTrue()
            ->and(explode("\r\n", substr($content, 3))[0])->toContain(';');

        expect(exportCsvRows($response))->toBe([
            VisitorExport::HEADINGS,
            ['Awa Koné', "'+225 07 00 00 00 01", "'+33 6 12 34 56 78", 'Cocody', 'Angré', 'Récurrent', '2',
                '02/08/2026', '06/09/2026', 'Sagesse, Force', 'Invité(e) par un membre', 'Marie K.'],
        ]);
    });

    it('neutralise les cellules interprétables comme formules', function (): void {
        AdminFixtures::visitor(['2026-09-01'], [
            'full_name' => "=cmd|' /C calc'!A0",
            'commune' => '+SUM(1,2)',
            'quartier' => '-2+3',
            'invited_by' => '@IMPORTXML("x")',
            'source' => Source::InviteMembre,
        ]);
        AdminFixtures::visitor(['2026-09-02'], ['full_name' => "\tTabulation", 'commune' => "\rRetour", 'quartier' => 'Normal = ok']);

        $rows = exportCsvRows($this->get('/api/admin/exports/visitors.csv?sort=created_at'));

        expect($rows[1][0])->toBe("'=cmd|' /C calc'!A0")
            ->and($rows[1][3])->toBe("'+SUM(1,2)")
            ->and($rows[1][4])->toBe("'-2+3")
            ->and($rows[1][11])->toBe("'@IMPORTXML(\"x\")")
            ->and($rows[2][0])->toBe("'\tTabulation")
            ->and($rows[2][3])->toBe("'\rRetour")
            ->and($rows[2][4])->toBe('Normal = ok');
    });

    it('exporte plus de 2 000 lignes sans aucun plafond', function (): void {
        AdminFixtures::bulkVisitors(2050);

        $rows = exportCsvRows($this->get('/api/admin/exports/visitors.csv?sort=full_name'));

        expect($rows)->toHaveCount(2051)
            ->and($rows[1][0])->toBe('Visiteur 00001')
            ->and($rows[2050][0])->toBe('Visiteur 02050')
            ->and(collect($rows)->skip(1)->pluck(0)->unique()->count())->toBe(2050)
            ->and(AuditLog::query()->sole()->meta['rows'])->toBe(2050);
    });

    it('lit la base par blocs sans perdre ni dupliquer de ligne', function (): void {
        app()->when(VisitorExport::class)->needs('$chunkSize')->give(3);
        foreach (range(1, 10) as $day) {
            AdminFixtures::visitor([sprintf('2026-09-%02d', $day)]);
        }

        $csvNames = collect(exportCsvRows($this->get('/api/admin/exports/visitors.csv?sort=-last_visit_date')))->skip(1)->pluck(0)->values()->all();
        $listNames = collect($this->getJson('/api/admin/visitors?sort=-last_visit_date')->json('data'))->pluck('full_name')->all();

        expect($csvNames)->toHaveCount(10)->toBe($listNames);
    });

    it('applique les mêmes filtres et le même ordre que la liste', function (): void {
        AdminFixtures::visitor(['2026-08-01', '2026-09-06'], ['full_name' => 'Awa Koné']);
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Traoré']);
        AdminFixtures::visitor(['2026-08-02', '2026-09-07'], ['full_name' => 'Yao Koné']);
        AdminFixtures::visitor(['2026-07-01'], ['full_name' => 'Awa Bamba']);

        $query = 'search=awa&family_id='.$this->families['Force']->id.'&sort=full_name';
        $csvNames = collect(exportCsvRows($this->get('/api/admin/exports/visitors.csv?'.$query)))->skip(1)->pluck(0)->values()->all();
        $listNames = collect($this->getJson('/api/admin/visitors?'.$query)->json('data'))->pluck('full_name')->all();

        expect($csvNames)->toBe(['Awa Koné', 'Awa Traoré'])->toBe($listNames);
    });

    it('journalise l\'export avec les filtres, sans le texte recherché', function (): void {
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Koné']);

        $this->get('/api/admin/exports/visitors.csv?search=Awa%20Kon%C3%A9&status=prospect')->assertOk();

        $log = AuditLog::query()->sole();

        expect($log->action)->toBe('export.csv')
            ->and($log->subject_type)->toBeNull()
            ->and($log->meta)->toBe(['filters' => ['search' => true, 'status' => 'prospect', 'sort' => '-created_at'], 'rows' => 1])
            ->and(json_encode($log->meta))->not->toContain('Awa');
    });

    it('rejette des filtres invalides (422) sans journaliser', function (string $format): void {
        $this->getJson("/api/admin/exports/visitors.{$format}?status=vip")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        expect(AuditLog::query()->count())->toBe(0);
    })->with(['csv', 'xlsx', 'pdf']);
});

describe('XLSX', function (): void {
    it('produit une archive XLSX valide contenant la feuille « Visiteurs », téléphones sans apostrophe', function (): void {
        AdminFixtures::visitor(['2026-08-02', '2026-09-06'], [
            'full_name' => 'Awa Koné',
            'phone' => '+2250700000001',
            'whatsapp' => '+33612345678',
            'commune' => 'Cocody',
        ]);
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => '=HYPERLINK("http://x")', 'quartier' => '@cmd']);

        $response = $this->get('/api/admin/exports/visitors.xlsx')->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->and($response->headers->get('Content-Disposition'))->toContain('visiteurs-2026-09-22-1000.xlsx');

        $sheet = (string) exportXlsxEntry($response, 'xl/worksheets/sheet1.xml');
        $workbook = (string) exportXlsxEntry($response, 'xl/workbook.xml');
        $rows = exportXlsxRows($sheet);
        $heading = XlsxVisitorExport::HEADING_ROW;

        expect($workbook)->toContain('name="Visiteurs"')
            ->and(array_values($rows[$heading]))->toBe(VisitorExport::HEADINGS)
            // Tri par défaut : inscription la plus récente d'abord. Valeurs telles quelles (sans préfixe).
            ->and($rows[$heading + 1][0])->toBe('=HYPERLINK("http://x")')
            ->and($rows[$heading + 1][4])->toBe('@cmd')
            ->and($rows[$heading + 2][0])->toBe('Awa Koné')
            ->and($rows[$heading + 2][1])->toBe('+225 07 00 00 00 01')
            ->and($rows[$heading + 2][2])->toBe('+33 6 12 34 56 78')
            ->and($rows[$heading + 2][3])->toBe('Cocody')
            ->and($rows[$heading + 2][6])->toBe('2')
            ->and($rows[$heading + 2][9])->toBe('Sagesse, Force')
            // Aucune formule écrite dans le classeur.
            ->and($sheet)->not->toContain('<f>')->not->toContain('<f ');
    });

    it('écrit « =cmd… » comme une chaîne de texte, jamais comme une formule', function (): void {
        AdminFixtures::visitor(['2026-09-01'], [
            'full_name' => "=cmd|' /C calc'!A0",
            'commune' => '+SUM(1,2)',
            'quartier' => '-2+3',
            'invited_by' => '@IMPORTXML("x")',
            'source' => Source::InviteMembre,
        ]);

        $sheet = (string) exportXlsxEntry($this->get('/api/admin/exports/visitors.xlsx'), 'xl/worksheets/sheet1.xml');
        $line = XlsxVisitorExport::HEADING_ROW + 1;

        $dom = new DOMDocument;
        $dom->loadXML($sheet);
        $cells = [];

        foreach ($dom->getElementsByTagName('c') as $cell) {
            $cells[$cell->getAttribute('r')] = $cell;
        }

        foreach (['A' => "=cmd|' /C calc'!A0", 'D' => '+SUM(1,2)', 'E' => '-2+3', 'L' => '@IMPORTXML("x")'] as $column => $value) {
            // Cellule de type chaîne en ligne (t="inlineStr"), valeur intacte dans <is><t>, aucun <f>.
            expect($cells[$column.$line]->getAttribute('t'))->toBe('inlineStr')
                ->and($cells[$column.$line]->getElementsByTagName('f')->length)->toBe(0)
                ->and($cells[$column.$line]->getElementsByTagName('t')->item(0)?->textContent)->toBe($value);
        }

        // La colonne numérique reste un nombre (cellule sans type texte).
        expect($cells['G'.$line]->hasAttribute('t'))->toBeFalse()
            ->and($cells['G'.$line]->getElementsByTagName('v')->item(0)?->textContent)->toBe('1')
            ->and($sheet)->not->toContain('<f>')->not->toContain('<f ');
    });

    it('exporte toutes les lignes (aucun plafond)', function (): void {
        AdminFixtures::bulkVisitors(1200);

        $sheet = exportXlsxEntry($this->get('/api/admin/exports/visitors.xlsx'), 'xl/worksheets/sheet1.xml');

        // 3 lignes de titre + en-tête + 1 200 données + mention finale (les lignes vides de
        // séparation ne produisent aucun élément <row>, mais décalent bien la numérotation).
        expect(substr_count((string) $sheet, '<row '))->toBe(XlsxVisitorExport::TITLE_ROWS + 1 + 1200 + 1)
            ->and(AuditLog::query()->sole()->action)->toBe('export.xlsx');
    });

    it('fixe une largeur pour chacune des douze colonnes (plus aucun intitulé tronqué)', function (): void {
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Koné']);

        $sheet = (string) exportXlsxEntry($this->get('/api/admin/exports/visitors.xlsx'), 'xl/worksheets/sheet1.xml');

        expect(XlsxVisitorExport::COLUMN_WIDTHS)->toHaveCount(count(VisitorExport::HEADINGS));

        preg_match_all('/<col min="(\d+)" max="(\d+)" width="([\d.]+)" customWidth="true"\/>/', $sheet, $cols, PREG_SET_ORDER);
        $widths = [];

        foreach ($cols as [, $min, $max, $width]) {
            for ($column = (int) $min; $column <= (int) $max; $column++) {
                $widths[$column] = (float) $width;
            }
        }

        ksort($widths);

        expect(array_values($widths))->toBe(array_map('floatval', XlsxVisitorExport::COLUMN_WIDTHS));

        // Chaque colonne est plus large que son intitulé (l'unité Excel vaut un caractère de la
        // police par défaut ; l'en-tête est en gras, d'où la marge d'un caractère).
        foreach (VisitorExport::HEADINGS as $index => $heading) {
            expect($widths[$index + 1])->toBeGreaterThan((float) mb_strlen($heading));
        }
    });

    it('met en valeur l\'en-tête des colonnes : fond violet, texte blanc et gras', function (): void {
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Koné']);

        $response = $this->get('/api/admin/exports/visitors.xlsx');
        $styles = (string) exportXlsxEntry($response, 'xl/styles.xml');
        $sheet = (string) exportXlsxEntry($response, 'xl/worksheets/sheet1.xml');

        // openspout préfixe les couleurs de POLICE par l'alpha (FF…), pas les couleurs de FOND.
        expect($styles)->toContain('<fgColor rgb="6B1F8A"/>')   // fond violet de la ligne d'en-tête
            ->toContain('<color rgb="FFFFFFFF"/>')              // texte blanc
            ->toContain('<color rgb="FF4A0E6B"/>')              // nom de l'église, violet profond
            ->toContain('<color rgb="E6DEEC"/>')                // filet clair des lignes de données
            ->toContain('<b/>');

        // L'en-tête et les titres portent bien un style (aucun ne reste sur le style par défaut 0).
        preg_match('/<row r="'.XlsxVisitorExport::HEADING_ROW.'"[^>]*>(.*?)<\/row>/s', $sheet, $row);
        preg_match_all('/<c r="[A-Z]+\d+" s="(\d+)"/', $row[1] ?? '', $cells);

        expect($cells[1])->toHaveCount(count(VisitorExport::HEADINGS))
            ->and(array_unique($cells[1]))->toHaveCount(1)
            ->and($cells[1][0])->not->toBe('0');
    });

    it('écrit les lignes de titre, les filtres et la mention de confidentialité', function (): void {
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Koné', 'commune' => 'Cocody']);

        $response = $this->get('/api/admin/exports/visitors.xlsx?status=prospect&search=Cocody');
        $sheet = (string) exportXlsxEntry($response, 'xl/worksheets/sheet1.xml');
        $rows = exportXlsxRows($sheet);
        $church = app(SettingsService::class)->churchName();

        // 3 titres, ligne vide, en-tête, 1 ligne de données, ligne vide, mention finale.
        $lastData = XlsxVisitorExport::HEADING_ROW + 1;
        $notice = $lastData + 2;

        expect($rows[1][0])->toBe($church)
            ->and($rows[2][0])->toContain(VisitorExport::SUBTITLE)->toContain('1 visiteur')
            ->and($rows[3][0])->toContain('Statut : Prospect')
            ->and($rows[3][0])->toContain('Recherche : active (texte non reproduit)')
            // Le texte recherché n'apparaît que dans les données, jamais dans la ligne de filtres.
            ->and($rows[3][0])->not->toContain('Cocody')
            ->and($rows[$notice][0])->toBe(VisitorExport::CONFIDENTIALITY);

        // Titres et mention finale fusionnés sur les douze colonnes.
        expect($sheet)->toContain('<mergeCell ref="A1:L1"/>')
            ->toContain('<mergeCell ref="A3:L3"/>')
            ->toContain('<mergeCell ref="A'.$notice.':L'.$notice.'"/>')
            // Volets figés sous l'en-tête et filtre automatique sur le tableau.
            ->toContain('state="frozen"')
            ->toContain('<autoFilter ref="A'.XlsxVisitorExport::HEADING_ROW.':L'.$lastData.'"/>');
    });

    it('reste un classeur valide quand aucun visiteur ne correspond', function (): void {
        expect(Visitor::query()->count())->toBe(0);

        $response = $this->get('/api/admin/exports/visitors.xlsx?status=membre')->assertOk();
        $sheet = (string) exportXlsxEntry($response, 'xl/worksheets/sheet1.xml');
        $rows = exportXlsxRows($sheet);
        $heading = XlsxVisitorExport::HEADING_ROW;

        expect($rows[2][0])->toContain('0 visiteur')
            ->and(array_values($rows[$heading]))->toBe(VisitorExport::HEADINGS)
            ->and($rows[$heading + 2][0])->toBe(VisitorExport::CONFIDENTIALITY)
            // Le filtre automatique se réduit à la ligne d'en-tête, sans plage invalide.
            ->and($sheet)->toContain('<autoFilter ref="A'.$heading.':L'.$heading.'"/>');
    });
});

describe('PDF', function (): void {
    it('produit un PDF A4 paysage et journalise l\'export', function (): void {
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Koné']);

        $response = $this->get('/api/admin/exports/visitors.pdf?status=prospect')->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Content-Disposition'))->toContain('visiteurs-2026-09-22-1000.pdf')
            ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-')
            // A4 paysage : 841,89 × 595,28 points.
            ->and((string) $response->getContent())->toMatch('/MediaBox \[0\.0+ 0\.0+ 841\.8\d* 595\.2\d*\]/');

        $log = AuditLog::query()->sole();

        expect($log->action)->toBe('export.pdf')
            ->and($log->meta)->toBe(['filters' => ['status' => 'prospect', 'sort' => '-created_at'], 'rows' => 1]);
    });

    it('intègre le logo de l\'église en data URI base64, sans aucune URL distante', function (): void {
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Koné']);

        $logo = app(ExportLogo::class);

        expect($logo->path())->toBeReadableFile()
            // Le logo est versionné dans api/ : un export ne dépend d'aucun fichier de web/.
            ->and(str_replace('\\', '/', $logo->path()))->toContain('/api/resources/images/logo.png')
            ->and(getimagesize($logo->path())[2])->toBe(IMAGETYPE_PNG);

        $html = app(PdfVisitorExport::class)->html(new VisitorListQuery, 1);

        expect($html)->toContain('src="data:image/png;base64,')
            ->and($html)->not->toContain('src="http')
            ->and($html)->not->toContain('logo.png')
            ->and(substr_count($html, '<img'))->toBe(1);

        // Le PNG se retrouve bien dans le document rendu (objet image du PDF).
        $pdf = (string) $this->get('/api/admin/exports/visitors.pdf')->getContent();
        expect($pdf)->toContain('/Subtype /Image')->not->toContain('http://')->not->toContain('https://');

        // Les fontes sont embarquées en sous-ensemble (préfixe à six lettres) : sans cela le
        // moindre export pèserait près d'un mégaoctet de DejaVu Sans.
        expect($pdf)->toMatch('/\/BaseFont \/[A-Z]{6}\+DejaVuSans/')
            ->and(strlen($pdf))->toBeLessThan(400 * 1024);
    });

    it('échappe les données dans la vue (XSS stockée)', function (): void {
        AdminFixtures::visitor(['2026-09-01'], [
            'full_name' => '<script>alert(1)</script>',
            'commune' => '<img src=x onerror=alert(2)>',
            'invited_by' => '"><b>gras</b>',
            'source' => Source::InviteMembre,
        ]);

        $html = app(PdfVisitorExport::class)->html(new VisitorListQuery, 1);

        expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->toContain('&lt;img src=x onerror=alert(2)&gt;')
            ->toContain('&quot;&gt;&lt;b&gt;gras&lt;/b&gt;')
            ->not->toContain('<script>')
            ->not->toContain('<img src=x')
            ->not->toContain('<b>');

        // Le seul <img> du document est le logo : la donnée hostile n'a produit aucune balise.
        expect(substr_count($html, '<img'))->toBe(1)
            ->and($html)->toContain('<img src="data:image/png;base64,');

        // Le PDF lui-même est généré sans erreur.
        $this->get('/api/admin/exports/visitors.pdf')->assertOk();
    });

    it('ne reproduit jamais le texte recherché, seulement la mention d\'une recherche active', function (): void {
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Koné', 'commune' => 'Cocody']);

        $secret = 'Koné<script>';
        $html = app(PdfVisitorExport::class)->html(new VisitorListQuery(search: $secret), 1);

        expect($html)->toContain('Recherche : active (texte non reproduit)')
            ->and($html)->not->toContain($secret)
            ->and($html)->not->toContain(e($secret));

        // Le document rendu ne contient pas davantage la saisie.
        $pages = exportPdfPages((string) $this->get('/api/admin/exports/visitors.pdf?search='.urlencode($secret))->getContent());

        expect($pages)->not->toBeEmpty()
            ->and(implode("\n", $pages))->toContain('Recherche : active')->not->toContain('<script>');
    });

    it('décrit les filtres appliqués en toutes lettres', function (): void {
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Koné']);

        $filters = app(VisitorExport::class)->describeFilters(new VisitorListQuery(
            search: 'Awa',
            status: VisitorListQuery::STATUS_NON_MEMBER,
            familyId: $this->families['Force']->id,
            from: '2026-01-01',
            to: '2026-09-30',
        ));

        expect($filters)->toBe([
            'Statut : tous sauf les membres',
            "Famille d'accueil : Force",
            '1re visite du 01/01/2026 au 30/09/2026',
            'Recherche : active (texte non reproduit)',
        ]);

        expect(app(VisitorExport::class)->describeFilters(new VisitorListQuery(status: 'membre_potentiel')))
            ->toBe(['Statut : Membre potentiel']);

        expect(app(VisitorExport::class)->describeFilters(new VisitorListQuery))->toBe([]);

        $html = app(PdfVisitorExport::class)->html(new VisitorListQuery, 1);
        expect($html)->toContain('aucun, tous les visiteurs du registre.');
    });

    it('porte l\'identité visuelle : en-tête avec le nom de l\'église et titre du document', function (): void {
        app(SettingsService::class)->set('church_name', 'Église de la Grâce');
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Koné']);

        $html = app(PdfVisitorExport::class)->html(new VisitorListQuery, 1);

        expect($html)->toContain('Église de la Grâce')
            ->toContain(VisitorExport::SUBTITLE)
            ->toContain(PdfVisitorExport::TITLE)
            // Violet profond, violet, violet clair et or de l'identité du SPA.
            ->toContain('#4A0E6B')->toContain('#6B1F8A')->toContain('#F3E8FF')->toContain('#C9A227');
    });

    it('répète l\'en-tête du tableau sur chaque page', function (): void {
        AdminFixtures::bulkVisitors(60);

        $html = app(PdfVisitorExport::class)->html(new VisitorListQuery(sort: 'full_name'), 60);

        expect($html)->toContain('display: table-header-group')
            ->and(substr_count($html, '<thead>'))->toBe(2)
            ->and(substr_count($html, '<th '))->toBe(2 * count(VisitorExport::HEADINGS));

        // L'en-tête se retrouve bien en tête de chacune des pages du document rendu.
        $pages = exportPdfPages((string) $this->get('/api/admin/exports/visitors.pdf?sort=full_name')->getContent());

        expect(count($pages))->toBeGreaterThan(1);

        foreach ($pages as $page) {
            expect($page)->toContain('Nom')->toContain('Téléphone')->toContain("Familles\nd'accueil");
        }
    });

    it('dimensionne les douze colonnes pour tenir en A4 paysage', function (): void {
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Koné']);

        expect(PdfVisitorExport::COLUMN_WIDTHS)->toHaveCount(count(VisitorExport::HEADINGS))
            ->and(array_sum(PdfVisitorExport::COLUMN_WIDTHS))->toBe(100.0);

        $html = app(PdfVisitorExport::class)->html(new VisitorListQuery, 1);

        // Les largeurs sont portées par les cellules de la 1re ligne (mise en page fixe dompdf).
        expect($html)->toContain('table-layout: fixed');

        foreach (PdfVisitorExport::COLUMN_WIDTHS as $width) {
            expect($html)->toContain('style="width: '.$width.'%"');
        }
    });

    it('imprime le pied de page sur chaque page : église, mention et pagination', function (): void {
        AdminFixtures::bulkVisitors(60);

        $pages = exportPdfPages((string) $this->get('/api/admin/exports/visitors.pdf?sort=full_name')->getContent());
        $total = count($pages);
        $church = app(SettingsService::class)->churchName();

        expect($total)->toBeGreaterThan(1);

        foreach ($pages as $index => $page) {
            expect($page)->toContain($church)
                ->toContain(VisitorExport::CONFIDENTIALITY)
                ->toContain(sprintf('Page %d / %d', $index + 1, $total));
        }
    });

    it('n\'utilise jamais la syntaxe Blade non échappée dans les vues d\'export', function (): void {
        foreach (glob(resource_path('views/exports/*.blade.php')) ?: [] as $view) {
            expect(file_get_contents($view))->not->toContain('{!!');
        }

        expect(glob(resource_path('views/exports/*.blade.php')))->not->toBeEmpty();
    });

    it('refuse au-delà de 1 000 lignes (422 too_many_rows) sans journaliser', function (): void {
        AdminFixtures::bulkVisitors(1001);

        $this->getJson('/api/admin/exports/visitors.pdf')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'too_many_rows')
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, "1\u{202F}000 lignes")
                && str_contains($message, "1\u{202F}001 visiteurs")
                && str_contains($message, 'CSV')
                && str_contains($message, 'Excel'));

        expect(AuditLog::query()->count())->toBe(0);

        // Avec un filtre ramenant le volume sous le seuil : export accepté (Visiteur 00001 à 00009).
        $this->get('/api/admin/exports/visitors.pdf?search=Visiteur%200000')->assertOk();
        expect(AuditLog::query()->sole()->meta['rows'])->toBe(9);
    });

    it('accepte exactement 1 000 lignes et refuse la 1 001e', function (): void {
        $pdf = app(PdfVisitorExport::class);

        $pdf->ensureWithinLimit(PdfVisitorExport::MAX_ROWS);

        expect(fn () => $pdf->ensureWithinLimit(PdfVisitorExport::MAX_ROWS + 1))
            ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('too_many_rows')->and($e->status)->toBe(422));
    });

    it('découpe les lignes en tableaux de 50 sans en perdre', function (): void {
        AdminFixtures::bulkVisitors(120);

        $html = app(PdfVisitorExport::class)->html(new VisitorListQuery(sort: 'full_name'), 120);

        // 1 <tr> pour l'en-tête de document, puis 3 tableaux (en-tête + lignes).
        expect(substr_count($html, '<table class="list">'))->toBe(3)
            ->and(substr_count($html, '<tr'))->toBe(1 + 3 + 120)
            ->and($html)->toContain('Visiteur 00001')->toContain('Visiteur 00120')
            ->and(strpos($html, 'Visiteur 00050'))->toBeLessThan(strpos($html, 'Visiteur 00051'));
    });

    it('fixe le seuil à 1 000 lignes', function (): void {
        expect(PdfVisitorExport::MAX_ROWS)->toBe(1000);
    });

    it('exporte un PDF vide proprement, avec un bloc lisible plutôt qu\'un tableau vide', function (): void {
        expect(Visitor::query()->count())->toBe(0);

        $html = app(PdfVisitorExport::class)->html(new VisitorListQuery(status: 'prospect'), 0);

        expect($html)->toContain('Aucun visiteur ne correspond aux filtres.')
            ->and($html)->not->toContain('<table class="list">')
            ->and($html)->not->toContain('<thead>');

        $pages = exportPdfPages((string) $this->get('/api/admin/exports/visitors.pdf')->getContent());

        expect($pages)->toHaveCount(1)
            ->and($pages[0])->toContain('Aucun visiteur ne correspond aux filtres.')
            ->toContain('Page 1 / 1');
    });
});

describe('cohérence des trois formats', function (): void {
    it('utilise exactement les mêmes intitulés de colonnes en CSV, XLSX et PDF', function (): void {
        AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Koné']);

        $csv = exportCsvRows($this->get('/api/admin/exports/visitors.csv'))[0];

        $sheet = (string) exportXlsxEntry($this->get('/api/admin/exports/visitors.xlsx'), 'xl/worksheets/sheet1.xml');
        $xlsx = array_values(exportXlsxRows($sheet)[XlsxVisitorExport::HEADING_ROW]);

        $html = app(PdfVisitorExport::class)->html(new VisitorListQuery, 1);
        preg_match_all('/<th style="width: [\d.]+%"[^>]*>(.*?)<\/th>/s', $html, $matches);
        $pdf = array_map(static fn (string $cell): string => html_entity_decode(trim($cell), ENT_QUOTES, 'UTF-8'), $matches[1]);

        expect($csv)->toBe(VisitorExport::HEADINGS)
            ->and($xlsx)->toBe(VisitorExport::HEADINGS)
            ->and($pdf)->toBe(VisitorExport::HEADINGS);
    });
});
