<?php

namespace App\Exports;

use App\Queries\VisitorListQuery;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export Excel (XLSX) en streaming avec openspout : les lignes sont écrites au fil de la lecture
 * (fichiers temporaires), l'archive est envoyée à la fermeture du classeur.
 *
 * Toutes les valeurs textuelles sont des StringCell explicites (jamais Cell::fromValue(), qui
 * transformerait « =… » en formule) : openspout les écrit en texte (t="inlineStr"), jamais en
 * formule, quel que soit leur premier caractère. Aucune neutralisation par apostrophe n'est donc
 * nécessaire (contrairement au CSV) : « +225 07… » s'affiche tel quel.
 *
 * MISE EN FORME — openspout NE SAIT PAS insérer d'image : le logo ne peut pas figurer dans le
 * classeur, l'identité visuelle repose donc uniquement sur la typographie et les couleurs
 * (titres violets, bandeau d'en-tête #6B1F8A à texte blanc, filets clairs, lignes alternées).
 * Tout ce qui est écrit ici existe réellement dans openspout 5.3 : styles de cellule, largeurs
 * de colonnes, hauteur de ligne, fusion de cellules, volets figés et filtre automatique.
 *
 * ATTENTION : StyleRegistry indexe les styles par spl_object_hash(). Les instances de Style
 * doivent donc être créées UNE fois et réutilisées d'une ligne à l'autre ; en construire une par
 * cellule enregistrerait des dizaines de milliers de styles dans styles.xml.
 */
class XlsxVisitorExport
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const SHEET_NAME = 'Visiteurs';

    /** Lignes de titre avant la ligne vide qui précède l'en-tête des colonnes. */
    public const TITLE_ROWS = 3;

    /** Ligne (1-indexée) de l'en-tête des colonnes : 3 lignes de titre + 1 ligne vide. */
    public const HEADING_ROW = self::TITLE_ROWS + 2;

    /** Fond des lignes alternées du tableau (violet très pâle, comme dans le PDF). */
    public const STRIPE = 'F7F1FB';

    /**
     * Largeur des colonnes en « caractères » Excel, dans l'ordre de VisitorExport::HEADINGS :
     * ajustée au plus long des intitulés et des valeurs attendues. « Nombre de visites »,
     * « Dernière visite » et « +225 07 00 00 0001 » ne doivent plus être tronqués à l'ouverture.
     *
     * @var list<float>
     */
    public const COLUMN_WIDTHS = [26.0, 20.0, 20.0, 16.0, 18.0, 17.0, 19.0, 13.0, 17.0, 22.0, 24.0, 20.0];

    public function __construct(
        private readonly VisitorExport $export,
        private readonly SettingsService $settings,
    ) {}

    public function download(VisitorListQuery $query, int $count, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query, $count): void {
            $options = new Options;

            foreach (self::COLUMN_WIDTHS as $index => $width) {
                $options->setColumnWidth($width, $index + 1);
            }

            $writer = new Writer($options);
            $writer->openToFile('php://output');

            $sheet = $writer->getCurrentSheet();
            $sheet->setName(self::SHEET_NAME);
            // Titres et en-tête des colonnes figés : ils restent visibles au défilement.
            $sheet->setSheetView(new SheetView(freezeRow: self::HEADING_ROW + 1));

            foreach ($this->titleRows($query, $count) as $row) {
                $writer->addRow($row);
            }

            $writer->addRow(new Row([]));
            $writer->addRow($this->headingRow());

            // Deux jeux de styles réutilisés à l'identique sur toutes les lignes (cf. classe).
            $text = [$this->dataStyle(null), $this->dataStyle(self::STRIPE)];
            $number = [$this->numberStyle(null), $this->numberStyle(self::STRIPE)];
            $line = 0;

            foreach ($this->export->rows($query) as $values) {
                $band = $line % 2;
                $writer->addRow(new Row(array_map(
                    static fn (int|string $value): Cell => self::cell($value, $text[$band], $number[$band]),
                    $values,
                )));
                $line++;
            }

            $columns = count(VisitorExport::HEADINGS);
            $lastRow = self::HEADING_ROW + $line;
            $sheet->setAutoFilter(new AutoFilter(0, self::HEADING_ROW, $columns - 1, $lastRow));

            $writer->addRow(new Row([]));
            $writer->addRow(new Row([new StringCell(VisitorExport::CONFIDENTIALITY, $this->noticeStyle())]));

            // Titres et mention finale déployés sur toute la largeur du tableau.
            foreach ([1, 2, 3, $lastRow + 2] as $row) {
                $options->mergeCells(0, $row, $columns - 1, $row);
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => self::CONTENT_TYPE,
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Trois lignes de titre : nom de l'église, nature et volume de l'export, filtres appliqués.
     *
     * @return list<Row>
     */
    private function titleRows(VisitorListQuery $query, int $count): array
    {
        $timezone = config('app.timezone');
        $generatedAt = CarbonImmutable::now(is_string($timezone) ? $timezone : 'Africa/Abidjan');
        $filters = $this->export->describeFilters($query);

        $subtitle = sprintf(
            '%s — export du %s · %d visiteur%s',
            VisitorExport::SUBTITLE,
            $generatedAt->format('d/m/Y à H:i'),
            $count,
            $count > 1 ? 's' : '',
        );

        return [
            new Row([new StringCell($this->settings->churchName(), new Style(
                fontBold: true,
                fontSize: 18,
                fontColor: '4A0E6B',
                fontName: 'Calibri',
                cellVerticalAlignment: CellVerticalAlignment::CENTER,
            ))], 30),
            new Row([new StringCell($subtitle, new Style(
                fontSize: 11,
                fontColor: '4B5563',
                fontName: 'Calibri',
            ))], 18),
            new Row([new StringCell(
                'Filtres : '.($filters === [] ? 'aucun, tous les visiteurs du registre.' : implode(' · ', $filters)),
                new Style(fontItalic: true, fontSize: 10, fontColor: '7A5F0C', fontName: 'Calibri'),
            )], 16),
        ];
    }

    /**
     * En-tête des colonnes : fond violet, texte blanc gras, hauteur de ligne confortable.
     */
    private function headingRow(): Row
    {
        $style = new Style(
            fontBold: true,
            fontSize: 11,
            fontColor: 'FFFFFF',
            fontName: 'Calibri',
            cellVerticalAlignment: CellVerticalAlignment::CENTER,
            backgroundColor: '6B1F8A',
        );

        return new Row(array_map(
            static fn (string $heading): Cell => new StringCell($heading, $style),
            VisitorExport::HEADINGS,
        ), 26);
    }

    /**
     * Cellules de texte : filet bas très clair, alignement en haut, fond alterné.
     */
    private function dataStyle(?string $background): Style
    {
        return new Style(
            fontSize: 11,
            fontName: 'Calibri',
            cellVerticalAlignment: CellVerticalAlignment::TOP,
            border: self::hairline(),
            backgroundColor: $background,
        );
    }

    /**
     * Cellules de la colonne « Nombre de visites » : mêmes filets, valeur alignée à droite.
     */
    private function numberStyle(?string $background): Style
    {
        return new Style(
            fontSize: 11,
            fontName: 'Calibri',
            cellAlignment: CellAlignment::RIGHT,
            cellVerticalAlignment: CellVerticalAlignment::TOP,
            border: self::hairline(),
            backgroundColor: $background,
        );
    }

    private function noticeStyle(): Style
    {
        return new Style(fontItalic: true, fontSize: 9, fontColor: '7A5F0C', fontName: 'Calibri');
    }

    private static function hairline(): Border
    {
        return new Border(new BorderPart(BorderName::BOTTOM, 'E6DEEC', BorderWidth::THIN, BorderStyle::SOLID));
    }

    private static function cell(int|string $value, Style $text, Style $number): Cell
    {
        if (is_int($value)) {
            return new NumericCell($value, $number);
        }

        // Cellule vide mais stylée : openspout l'écrit quand même (fond et filet conservés).
        return $value === '' ? new EmptyCell(null, $text) : new StringCell($value, $text);
    }
}
