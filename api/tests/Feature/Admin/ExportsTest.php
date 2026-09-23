<?php

use App\Enums\Source;
use App\Exceptions\ApiException;
use App\Exports\PdfVisitorExport;
use App\Exports\VisitorExport;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Visitor;
use App\Queries\VisitorListQuery;
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
 * Valeurs (texte décodé) des cellules de la 1re feuille XLSX, indexées par colonne (A = 0).
 *
 * @return list<array<int, string>>
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

        $rows[] = $cells;
    }

    return $rows;
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
            ['Awa Koné', "'+225 07 00 00 0001", "'+33 6 12 34 56 78", 'Cocody', 'Angré', 'Récurrent', '2',
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

        expect($workbook)->toContain('name="Visiteurs"')
            ->and($rows)->toHaveCount(3)
            ->and(array_values($rows[0]))->toBe(VisitorExport::HEADINGS)
            // Tri par défaut : inscription la plus récente d'abord. Valeurs telles quelles (sans préfixe).
            ->and($rows[1][0])->toBe('=HYPERLINK("http://x")')
            ->and($rows[1][4])->toBe('@cmd')
            ->and($rows[2][0])->toBe('Awa Koné')
            ->and($rows[2][1])->toBe('+225 07 00 00 0001')
            ->and($rows[2][2])->toBe('+33 6 12 34 56 78')
            ->and($rows[2][3])->toBe('Cocody')
            ->and($rows[2][6])->toBe('2')
            ->and($rows[2][9])->toBe('Sagesse, Force')
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

        $dom = new DOMDocument;
        $dom->loadXML($sheet);
        $cells = [];

        foreach ($dom->getElementsByTagName('c') as $cell) {
            $cells[$cell->getAttribute('r')] = $cell;
        }

        foreach (['A2' => "=cmd|' /C calc'!A0", 'D2' => '+SUM(1,2)', 'E2' => '-2+3', 'L2' => '@IMPORTXML("x")'] as $ref => $value) {
            // Cellule de type chaîne en ligne (t="inlineStr"), valeur intacte dans <is><t>, aucun <f>.
            expect($cells[$ref]->getAttribute('t'))->toBe('inlineStr')
                ->and($cells[$ref]->getElementsByTagName('f')->length)->toBe(0)
                ->and($cells[$ref]->getElementsByTagName('t')->item(0)?->textContent)->toBe($value);
        }

        // La colonne numérique reste un nombre (cellule sans type texte).
        expect($cells['G2']->hasAttribute('t'))->toBeFalse()
            ->and($cells['G2']->getElementsByTagName('v')->item(0)?->textContent)->toBe('1')
            ->and($sheet)->not->toContain('<f>')->not->toContain('<f ');
    });

    it('exporte toutes les lignes (aucun plafond)', function (): void {
        AdminFixtures::bulkVisitors(1200);

        $sheet = exportXlsxEntry($this->get('/api/admin/exports/visitors.xlsx'), 'xl/worksheets/sheet1.xml');

        expect(substr_count((string) $sheet, '<row '))->toBe(1201)
            ->and(AuditLog::query()->sole()->action)->toBe('export.xlsx');
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

    it('échappe les données dans la vue (XSS stockée)', function (): void {
        AdminFixtures::visitor(['2026-09-01'], [
            'full_name' => '<script>alert(1)</script>',
            'commune' => '<img src=x onerror=alert(2)>',
            'invited_by' => '"><b>gras</b>',
            'source' => Source::InviteMembre,
        ]);

        $query = new VisitorListQuery(search: '<script>');
        $html = app(PdfVisitorExport::class)->html($query, 1);

        expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->toContain('&lt;img src=x onerror=alert(2)&gt;')
            ->toContain('&quot;&gt;&lt;b&gt;gras&lt;/b&gt;')
            ->not->toContain('<script>')
            ->not->toContain('<img')
            ->not->toContain('<b>');

        // Le PDF lui-même est généré sans erreur.
        $this->get('/api/admin/exports/visitors.pdf')->assertOk();
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

        expect(substr_count($html, '<table>'))->toBe(3)
            ->and(substr_count($html, '<tr>'))->toBe(3 + 120)
            ->and($html)->toContain('Visiteur 00001')->toContain('Visiteur 00120')
            ->and(strpos($html, 'Visiteur 00050'))->toBeLessThan(strpos($html, 'Visiteur 00051'));
    });

    it('fixe le seuil à 1 000 lignes', function (): void {
        expect(PdfVisitorExport::MAX_ROWS)->toBe(1000);
    });

    it('exporte un PDF vide proprement', function (): void {
        expect(Visitor::query()->count())->toBe(0);

        $this->get('/api/admin/exports/visitors.pdf')->assertOk();
    });
});
