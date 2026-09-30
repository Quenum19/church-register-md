<?php

namespace App\Exports;

use App\Enums\Source;
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
 * Fiche d'inscription papier d'un événement (A4 portrait), deuxième voie d'enregistrement à
 * côté du QR code : personne sans téléphone, file d'attente, batterie vide.
 *
 * Les champs reprennent exactement le formulaire allégé du lien événement
 * (web/src/public/event/Visit1EventPage.tsx) : identité, origine réduite à deux choix,
 * groupe WhatsApp facultatif, consentement. Le numéro de téléphone, saisi à l'accueil dans
 * le parcours écran, est ici en tête de fiche : c'est lui qui identifie la personne.
 *
 * Sécurité identique aux exports de visiteurs : vue Blade en `{{ }}` uniquement, dompdf sans
 * PHP, sans JavaScript et sans ressource distante ; logo et QR code intégrés en data URI.
 */
class EventFormExport
{
    public const VIEW = 'exports.event-form';

    public const TITLE = "Fiche d'inscription";

    /** Nombre de fiches par page A4 accepté par l'endpoint. */
    public const PER_PAGE = [1, 2];

    public const DEFAULT_PER_PAGE = 2;

    /** Cases à un chiffre du numéro de téléphone (format ivoirien : 10 chiffres). */
    public const PHONE_BOXES = 10;

    /** Mot pour mot ce que la personne coche à l'écran (Visit1EventPage). */
    public const CONSENT = "J'accepte que l'Église enregistre ces informations pour assurer mon suivi pastoral.";

    public const NOTICE = 'Réservées aux responsables de l\'Église et conservées 24 mois après votre dernière visite.';

    public const WHATSAPP = 'Rejoindre le Groupe WhatsApp des nouvelles personnes pour une période de 3 mois.';

    public const SOURCE_QUESTION = "Comment avez-vous connu l'Église ?";

    public const QR_HINT = 'Vous pouvez aussi vous enregistrer vous-même en scannant ce code.';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ExportLogo $logo,
    ) {}

    public function download(Event $event, int $perPage, string $filename): Response
    {
        /** @var PdfWrapper $pdf */
        $pdf = Pdf::setOption([
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            // Même raison que l'export des visiteurs : sans sous-ensemble, dompdf embarque
            // 1,4 Mo de DejaVu Sans dans un document d'une seule page.
            'isFontSubsettingEnabled' => true,
        ])
            ->loadHTML($this->html($event, $perPage))
            ->setPaper('a4', 'portrait');

        return $pdf->download($filename)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Nom du fichier téléchargé : formulaire-{slug}.pdf.
     */
    public function filename(Event $event): string
    {
        return 'formulaire-'.$event->slug.'.pdf';
    }

    /**
     * HTML du document (exposé pour les tests d'échappement et de mise en page).
     */
    public function html(Event $event, int $perPage): string
    {
        $url = $event->publicUrl();

        return view(self::VIEW, [
            'churchName' => $this->settings->churchName(),
            'title' => self::TITLE,
            'logo' => $this->logo->dataUri(),
            'eventName' => $event->name,
            'eventDate' => self::longDate($event->event_date),
            'url' => $url,
            'qr' => $this->qr($url),
            'perPage' => in_array($perPage, self::PER_PAGE, true) ? $perPage : self::DEFAULT_PER_PAGE,
            'phoneBoxes' => self::PHONE_BOXES,
            'sourceQuestion' => self::SOURCE_QUESTION,
            'invitedLabel' => Source::InviteMembre->label(),
            'otherLabel' => Source::Autre->label(),
            'whatsapp' => self::WHATSAPP,
            'consent' => self::CONSENT,
            'notice' => self::NOTICE,
            'qrHint' => self::QR_HINT,
        ])->render();
    }

    /**
     * QR code du lien public, en data URI PNG. Une URL démesurée (paramètre `public_url`
     * fantaisiste) ne doit pas faire échouer l'impression : la fiche sort alors sans code.
     */
    private function qr(string $url): string
    {
        try {
            return QrPng::dataUri(QrCode::encode($url));
        } catch (Throwable $exception) {
            Log::warning('QR code de la fiche papier non généré', ['message' => $exception->getMessage()]);

            return '';
        }
    }

    /**
     * « dimanche 4 octobre 2026 » ; le premier du mois s'écrit « 1er » en français.
     */
    private static function longDate(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $day = $date->day === 1 ? '1er' : (string) $date->day;

        return $date->locale('fr')->translatedFormat('l').' '.$day.' '.$date->locale('fr')->translatedFormat('F Y');
    }
}
