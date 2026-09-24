<?php

namespace App\Exports;

use App\Enums\Source;
use App\Enums\VisitorStatus;
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
        '1re visite',
        'Dernière visite',
        "Familles d'accueil",
        'Source',
        'Invité(e) par',
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

        if ($query->from !== null && $query->to !== null) {
            $filters[] = '1re visite du '.$this->day($query->from).' au '.$this->day($query->to);
        } elseif ($query->from !== null) {
            $filters[] = '1re visite à partir du '.$this->day($query->from);
        } elseif ($query->to !== null) {
            $filters[] = "1re visite jusqu'au ".$this->day($query->to);
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
     * Lignes de données (sans en-tête), lues par blocs.
     *
     * @return Generator<int, list<int|string>>
     */
    public function rows(VisitorListQuery $query): Generator
    {
        $families = [];

        foreach (Family::query()->get(['id', 'name']) as $family) {
            $families[$family->id] = $family->name;
        }

        $visitors = $query->builder()
            // Seules les colonnes utiles des visites (familles d'accueil), chargées par bloc.
            ->with('visits:id,visitor_id,visit_number,family_id')
            ->lazy($this->chunkSize);

        foreach ($visitors as $visitor) {
            yield $this->row($visitor, $families);
        }
    }

    /**
     * @param  array<int, string>  $families  noms des familles par identifiant
     * @return list<int|string>
     */
    private function row(Visitor $visitor, array $families): array
    {
        $hostFamilies = $visitor->visits
            ->map(static fn (Visit $visit): ?string => $visit->family_id !== null ? ($families[$visit->family_id] ?? null) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            $visitor->full_name,
            PhoneNumberService::formatInternational($visitor->phone),
            $visitor->whatsapp !== null ? PhoneNumberService::formatInternational($visitor->whatsapp) : '',
            $visitor->commune,
            $visitor->quartier,
            $this->status($visitor->status),
            (int) $visitor->visits_count,
            $this->date($visitor->visits_min_visit_date),
            $this->date($visitor->visits_max_visit_date),
            implode(', ', $hostFamilies),
            $this->source($visitor),
            $visitor->invited_by ?? '',
        ];
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
