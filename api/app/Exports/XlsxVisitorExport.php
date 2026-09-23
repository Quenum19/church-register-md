<?php

namespace App\Exports;

use App\Queries\VisitorListQuery;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
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
 */
class XlsxVisitorExport
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const SHEET_NAME = 'Visiteurs';

    public function __construct(private readonly VisitorExport $export) {}

    public function download(VisitorListQuery $query, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->getCurrentSheet()->setName(self::SHEET_NAME);

            $bold = new Style(fontBold: true);
            $writer->addRow(new Row(array_map(
                static fn (string $heading): Cell => new StringCell($heading, $bold),
                VisitorExport::HEADINGS,
            )));

            foreach ($this->export->rows($query) as $values) {
                $writer->addRow(new Row(array_map(self::cell(...), $values)));
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => self::CONTENT_TYPE,
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private static function cell(int|string $value): Cell
    {
        if (is_int($value)) {
            return new NumericCell($value);
        }

        return $value === '' ? new EmptyCell(null) : new StringCell($value);
    }
}
