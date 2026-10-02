<?php

namespace App\Exports;

use App\Services\SettingsService;
use App\Support\QrCode;
use App\Support\QrPng;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Affichette « Suivez-nous » à poser sur les tables : un QR code qui mène à la page publique
 * des réseaux sociaux de l'Église (/reseaux).
 *
 * Une page A5 — le format des porte-affiches posés sur les tables — avec le logo, le nom de
 * l'Église, un QR code de 72 mm lisible à bout de bras, l'adresse en clair et la liste des
 * réseaux renseignés.
 *
 * Sécurité identique aux autres exports : vue Blade en `{{ }}` uniquement, dompdf sans PHP,
 * sans JavaScript et sans ressource distante ; logo et QR code intégrés en data URI.
 */
class SocialPosterExport
{
    public const VIEW = 'exports.social-poster';

    public const TITLE = 'Suivez-nous';

    public const SUBTITLE = 'Scannez ce code pour retrouver et suivre nos réseaux.';

    /** Chemin de la page publique des réseaux sociaux, relatif à l'adresse publique. */
    public const PATH = '/reseaux';

    public const FILENAME = 'reseaux-sociaux.pdf';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ExportLogo $logo,
    ) {}

    public function download(): Response
    {
        /** @var PdfWrapper $pdf */
        $pdf = Pdf::setOption([
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'isFontSubsettingEnabled' => true,
        ])
            ->loadHTML($this->html())
            ->setPaper('a5', 'portrait');

        return $pdf->download(self::FILENAME)->header('Cache-Control', 'no-store, private');
    }

    /**
     * URL encodée dans le QR code : la page publique « Suivez-nous ».
     */
    public function url(): string
    {
        return rtrim($this->settings->publicUrl(), '/').self::PATH;
    }

    /**
     * HTML du document (exposé pour les tests d'échappement et de mise en page).
     */
    public function html(): string
    {
        $url = $this->url();

        return view(self::VIEW, [
            'churchName' => $this->settings->churchName(),
            'title' => self::TITLE,
            'subtitle' => self::SUBTITLE,
            'logo' => $this->logo->dataUri(),
            'url' => $url,
            'qr' => $this->qr($url),
            'networks' => $this->settings->socialNetworks(),
        ])->render();
    }

    /**
     * Une adresse publique fantaisiste ne doit pas empêcher l'impression : la carte sort
     * alors sans code, avec l'adresse en clair.
     */
    private function qr(string $url): string
    {
        try {
            return QrPng::dataUri(QrCode::encode($url));
        } catch (Throwable $exception) {
            Log::warning('QR code des réseaux sociaux non généré', ['message' => $exception->getMessage()]);

            return '';
        }
    }
}
