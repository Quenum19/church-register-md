<?php

namespace App\Exports;

use App\Enums\Source;
use App\Enums\VisitorStatus;
use App\Models\Event;
use App\Models\Family;
use App\Models\Visit;
use App\Models\Visitor;
use App\Queries\VisitorListQuery;
use App\Services\PhoneNumberService;
use Carbon\CarbonImmutable;
use Generator;

/**
 * Lignes des exports de visiteurs (CSV, Excel, PDF), communes aux trois formats.
 *
 * Lecture par blocs (lazy()) de la requête partagée VisitorListQuery : mêmes filtres et même
 * ordre que la liste, AUCUN plafond de lignes, mémoire constante quel que soit le volume.
 */
class VisitorExport
{
    /** Taille des blocs lus en base. */
    public const CHUNK_SIZE = 500;

    /** @var list<string> En-têtes, dans l'ordre des colonnes. */
    public const HEADINGS = [
        'Nom',
        'Téléphone',
        'WhatsApp',
        'Commune',
        'Quartier',
        'Statut',
        'Nombre de visites',
        'Première visite',
        'Dernière visite',
        "Familles d'accueil",
        'Source',
        'Invité(e) par',
        // Congrégation de la personne qui a invité (l'Église en compte plusieurs).
        "Congrégation de l'invitant",
        // Événement d'origine de la 1re visite (culte spécial, évangélisation), vide sinon.
        'Événement',
    ];

    /** Index de la colonne numérique « Nombre de visites ». */
    public const VISIT_COUNT_COLUMN = 6;

    /** Mention de confidentialité en pied de document (PDF et Excel). */
    public const CONFIDENTIALITY = 'Document interne — contient des données personnelles';

    /** Sous-titre du document, sous le nom de l'église. */
    public const SUBTITLE = 'Registre des visiteurs';

    public function __construct(private readonly int $chunkSize = self::CHUNK_SIZE) {}

    /**
     * Filtres appliqués, en toutes lettres, pour l'en-tête des exports PDF et Excel.
     *
     * Le TEXTE RECHERCHÉ n'est JAMAIS reproduit (il peut contenir un nom ou un numéro de
     * téléphone, et le document est destiné à circuler) : seule la mention qu'une recherche
     * était active figure, exactement comme dans le journal d'audit des exports
     * (VisitorListQuery::auditFilters()).
     *
     * @return list<string>
     */
    public function describeFilters(VisitorListQuery $query): array
    {
        $filters = [];

        if ($query->status === VisitorListQuery::STATUS_NON_MEMBER) {
            $filters[] = 'Statut : tous sauf les membres';
        } elseif ($query->status !== null) {
            $filters[] = 'Statut : '.(VisitorStatus::tryFrom($query->status)?->label() ?? $query->status);
        }

        if ($query->familyId !== null) {
            $name = Family::query()->whereKey($query->familyId)->value('name');
            $filters[] = "Famille d'accueil : ".(is_string($name) ? $name : '#'.$query->familyId);
        }

        if ($query->eventId !== null) {
            $name = Event::query()->whereKey($query->eventId)->value('name');
            $filters[] = 'Événement : '.(is_string($name) ? $name : '#'.$query->eventId);
        }

        if ($query->from !== null && $query->to !== null) {
            $filters[] = 'Première visite du '.$this->day($query->from).' au '.$this->day($query->to);
        } elseif ($query->from !== null) {
            $filters[] = 'Première visite à partir du '.$this->day($query->from);
        } elseif ($query->to !== null) {
            $filters[] = "Première visite jusqu'au ".$this->day($query->to);
        }

        if ($query->search !== null) {
            $filters[] = 'Recherche : active (texte non reproduit)';
        }

        return $filters;
    }

    /**
     * Nombre de lignes de l'export (même requête que rows()).
     */
    public function count(VisitorListQuery $query): int
    {
        return $query->builder()->count();
    }

    /**
     * Lignes de données (sans en-tête), lues par blocs : les valeurs de chaque fiche, dans
     * l'ordre de HEADINGS. Format des exports tabulaires (CSV, Excel).
     *
     * @return Generator<int, list<int|string>>
     */
    public function rows(VisitorListQuery $query): Generator
    {
        foreach ($this->records($query) as $record) {
            yield array_values($record);
        }
    }

    /**
     * Mêmes données que rows(), mais indexées par nom de champ : l'export PDF compose une mise
     * en page (nom et origine dans la même cellule, WhatsApp sous le téléphone…) et a besoin de
     * désigner les champs, pas de leur rang.
     *
     * Les clés suivent l'ordre de HEADINGS : `array_values()` redonne exactement une ligne CSV.
     *
     * @return Generator<int, array{name: string, phone: string, whatsapp: string, commune: string, quartier: string, status: string, visits: int, first: string, last: string, families: string, source: string, invitedBy: string, congregation: string, event: string}>
     */
    public function records(VisitorListQuery $query): Generator
    {
        $families = [];

        foreach (Family::query()->get(['id', 'name']) as $family) {
            $families[$family->id] = $family->name;
        }

        // Les événements sont peu nombreux (un par culte spécial) : leur table entière tient en
        // mémoire, comme celle des familles, et évite une jointure par bloc.
        $events = [];

        foreach (Event::query()->get(['id', 'name']) as $event) {
            $events[$event->id] = $event->name;
        }

        $visitors = $query->builder()
            // Seules les colonnes utiles des visites (familles d'accueil, événement), par bloc.
            ->with('visits:id,visitor_id,visit_number,family_id,event_id')
            ->lazy($this->chunkSize);

        foreach ($visitors as $visitor) {
            yield $this->record($visitor, $families, $events);
        }
    }

    /**
     * @param  array<int, string>  $families  noms des familles par identifiant
     * @param  array<int, string>  $events  noms des événements par identifiant
     * @return array{name: string, phone: string, whatsapp: string, commune: string, quartier: string, status: string, visits: int, first: string, last: string, families: string, source: string, invitedBy: string, congregation: string, event: string}
     */
    private function record(Visitor $visitor, array $families, array $events): array
    {
        $hostFamilies = $visitor->visits
            ->map(static fn (Visit $visit): ?string => $visit->family_id !== null ? ($families[$visit->family_id] ?? null) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'name' => $visitor->full_name,
            'phone' => PhoneNumberService::formatInternational($visitor->phone),
            'whatsapp' => $visitor->whatsapp !== null ? PhoneNumberService::formatInternational($visitor->whatsapp) : '',
            'commune' => $visitor->commune,
            'quartier' => $visitor->quartier,
            'status' => $this->status($visitor->status),
            'visits' => (int) $visitor->visits_count,
            'first' => $this->date($visitor->visits_min_visit_date),
            'last' => $this->date($visitor->visits_max_visit_date),
            'families' => implode(', ', $hostFamilies),
            'source' => $this->source($visitor),
            'invitedBy' => $visitor->invited_by ?? '',
            'congregation' => $visitor->inviter_congregation ?? '',
            'event' => $this->event($visitor, $events),
        ];
    }

    /**
     * Événement d'origine : celui de la 1re visite (la seule faite depuis le lien dédié),
     * chaîne vide pour une inscription par le lien ordinaire.
     *
     * @param  array<int, string>  $events
     */
    private function event(Visitor $visitor, array $events): string
    {
        $eventId = $visitor->visits->firstWhere('visit_number', 1)?->event_id;

        return $eventId !== null ? ($events[$eventId] ?? '') : '';
    }

    private function status(VisitorStatus $status): string
    {
        return $status->label();
    }

    private function source(Visitor $visitor): string
    {
        $label = $visitor->source->label();

        return $visitor->source === Source::Autre && $visitor->source_other !== null && $visitor->source_other !== ''
            ? $label.' : '.$visitor->source_other
            : $label;
    }

    /**
     * Date de filtre (« YYYY-MM-DD » validé par VisitorListQuery) => « JJ/MM/AAAA ».
     */
    private function day(string $value): string
    {
        return CarbonImmutable::parse($value)->format('d/m/Y');
    }

    /**
     * « YYYY-MM-DD » (agrégat SQL) => « JJ/MM/AAAA ».
     */
    private function date(?string $value): string
    {
        if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $parts) !== 1) {
            return '';
        }

        return "{$parts[3]}/{$parts[2]}/{$parts[1]}";
    }
}
