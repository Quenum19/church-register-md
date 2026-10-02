<?php

namespace App\Exports;

use App\Models\Event;
use App\Services\SettingsService;
use App\Support\QrCode;
use App\Support\QrPng;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;
use Carbon\CarbonInterface;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Affiche du QR code d'inscription, en A5 portrait (format des porte-affiches de l'Église) :
 * celle du formulaire habituel, ou celle d'un culte spécial quand un événement est fourni.
 *
 * Reprend la maquette de l'affiche affichée dans le dashboard (web/src/admin/lib/poster.ts) :
 * bandeau violet, nom de l'Église, nom et date de l'événement, QR code encadré, verset, adresse.
 * Le PDF est destiné à l'impression ; le PNG du dashboard reste pratique pour un partage rapide.
 *
 * Sécurité identique aux autres exports : vue Blade en `{{ }}` uniquement, dompdf sans PHP,
 * sans JavaScript et sans ressource distante ; QR code intégré en data URI.
 */
class PosterExport
{
    public const VIEW = 'exports.poster';

    public const INSTRUCTION = "Ouvrez l'appareil photo de votre téléphone et visez le code.";

    public const EVENT_HEADLINE = 'Scannez pour vous inscrire';

    public const VISIT_HEADLINE = 'Scannez pour enregistrer votre visite';

    public const VISIT_SUBTITLE = 'Bienvenue parmi nous';

    public const EVENT_SUBTITLE = 'Culte spécial';

    public function __construct(private readonly SettingsService $settings) {}

    public function download(?Event $event = null): Response
    {
        /** @var PdfWrapper $pdf */
        $pdf = Pdf::setOption([
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'isFontSubsettingEnabled' => true,
        ])
            ->loadHTML($this->html($event))
            ->setPaper('a5', 'portrait');

        return $pdf->download($this->filename($event))->header('Cache-Control', 'no-store, private');
    }

    public function filename(?Event $event = null): string
    {
        return $event === null ? 'affiche-qr-code.pdf' : 'affiche-'.$event->slug.'.pdf';
    }

    /**
     * Adresse encodée : le lien de l'événement, ou le formulaire habituel.
     */
    public function url(?Event $event = null): string
    {
        return $event === null ? $this->settings->publicUrl() : $event->publicUrl();
    }

    /**
     * HTML du document (exposé pour les tests d'échappement et de mise en page).
     */
    public function html(?Event $event = null): string
    {
        $url = $this->url($event);
        $verse = $this->settings->verse();

        return view(self::VIEW, [
            'churchName' => $this->settings->churchName(),
            'eventName' => $event?->name,
            'subtitle' => $event === null
                ? self::VISIT_SUBTITLE
                : (self::longDate($event->event_date) ?: self::EVENT_SUBTITLE),
            'headline' => $event === null ? self::VISIT_HEADLINE : self::EVENT_HEADLINE,
            'instruction' => self::INSTRUCTION,
            'verseRef' => $verse['ref'],
            'verseText' => $verse['text'],
            'url' => $url,
            'qr' => $this->qr($url),
        ])->render();
    }

    /**
     * Une adresse publique fantaisiste ne doit pas empêcher l'impression : l'affiche sort
     * alors sans code, avec l'adresse en clair.
     */
    private function qr(string $url): string
    {
        try {
            return QrPng::dataUri(QrCode::encode($url));
        } catch (Throwable $exception) {
            Log::warning('QR code de l\'affiche non généré', ['message' => $exception->getMessage()]);

            return '';
        }
    }

    /**
     * « Dimanche 4 octobre 2026 » ; le premier du mois s'écrit « 1er » en français.
     */
    private static function longDate(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $day = $date->day === 1 ? '1er' : (string) $date->day;
        $long = $date->locale('fr')->translatedFormat('l').' '.$day.' '.$date->locale('fr')->translatedFormat('F Y');

        return mb_ucfirst($long);
    }
}
