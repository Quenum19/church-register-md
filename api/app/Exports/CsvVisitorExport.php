<?php

namespace App\Exports;

use App\Queries\VisitorListQuery;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export CSV en streaming : UTF-8 avec BOM (Excel), séparateur `;`, en-têtes en français,
 * cellules « formule » neutralisées (neutralize()). L'export XLSX n'en a pas besoin : ses cellules
 * texte sont typées chaîne et ne peuvent jamais devenir des formules.
 */
class CsvVisitorExport
{
    public const DELIMITER = ';';

    public const BOM = "\xEF\xBB\xBF";

    /** Premiers caractères qu'un tableur interprète comme une formule (injection CSV / DDE). */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Blancs et caractères invisibles qu'un tableur ignore avant d'interpréter la cellule :
     * espaces (y compris insécables et Unicode), BOM, espace de largeur nulle, marques de
     * direction. « \u{00A0}=1+1 » doit être neutralisé comme « =1+1 ».
     */
    private const INVISIBLE = '~^[\s\x{00A0}\x{FEFF}\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2060}]+~u';

    public function __construct(private readonly VisitorExport $export) {}

    public function download(VisitorListQuery $query, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fwrite($output, self::BOM);
            $this->write($output, VisitorExport::HEADINGS);

            foreach ($this->export->rows($query) as $row) {
                $this->write($output, $row);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Neutralise une cellule qu'un tableur interpréterait comme une formule
     * (`= + - @ \t \r` en tête) en la préfixant d'une apostrophe.
     *
     * Le premier caractère NON invisible décide : « \u{00A0}=1+1 » ou «   =1+1 » sont des
     * formules pour un tableur, qui ignore les blancs de tête. Tester `$value[0]` seul les
     * laissait passer (contournement trivial de la neutralisation).
     */
    public static function neutralize(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $significant = (string) preg_replace(self::INVISIBLE, '', $value);

        $triggers = in_array($value[0], self::FORMULA_TRIGGERS, true)
            || ($significant !== '' && in_array($significant[0], self::FORMULA_TRIGGERS, true));

        return $triggers ? "'".$value : $value;
    }

    /**
     * @param  resource  $output
     * @param  list<int|string>  $row
     */
    private function write($output, array $row): void
    {
        $cells = array_map(
            static fn (int|string $value): string => is_int($value) ? (string) $value : self::neutralize($value),
            $row,
        );

        fputcsv($output, $cells, self::DELIMITER, '"', '', "\r\n");
    }
}
