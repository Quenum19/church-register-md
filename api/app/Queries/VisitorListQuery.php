<?php

namespace App\Queries;

use App\Enums\VisitorStatus;
use App\Models\Congregation;
use App\Models\Visitor;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Liste filtrée et triée des visiteurs, PARTAGÉE par GET /api/admin/visitors et les exports
 * (contrat d'API §4) : les deux voient exactement les mêmes lignes, dans le même ordre.
 *
 * Tous les filtres se combinent en ET. La recherche est un LIKE paramétré dont les jokers
 * (`%`, `_`) et le caractère d'échappement (`\`) sont neutralisés : aucune regex n'est jamais
 * construite à partir de la saisie.
 */
final class VisitorListQuery
{
    public const SEARCH_MAX_LENGTH = 100;

    public const STATUS_NON_MEMBER = 'non_membre';

    /** Valeur du filtre « congrégation » désignant les visiteurs dont elle n'est pas renseignée. */
    public const NO_CONGREGATION = 'aucune';

    public const DEFAULT_SORT = '-created_at';

    /** Tris autorisés (liste blanche). */
    public const SORTS = ['-created_at', 'created_at', 'full_name', '-last_visit_date'];

    /** Une saisie « de type téléphone » : chiffres et séparateurs usuels uniquement. */
    private const PHONE_LIKE = '/^[0-9+().\-\s]+$/u';

    public function __construct(
        public readonly ?string $search = null,
        public readonly ?string $status = null,
        public readonly ?int $familyId = null,
        public readonly ?int $congregationId = null,
        public readonly bool $withoutCongregation = false,
        public readonly ?int $eventId = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly string $sort = self::DEFAULT_SORT,
    ) {}

    /**
     * Règles de validation des filtres (paramètre invalide => 422).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:'.self::SEARCH_MAX_LENGTH],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([...VisitorStatus::values(), self::STATUS_NON_MEMBER])],
            'family_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:families,id'],
            // Un identifiant de congrégation, ou « aucune » pour celles qui restent à renseigner.
            'congregation_id' => ['sometimes', 'nullable', static function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === self::NO_CONGREGATION) {
                    return;
                }

                if (! is_numeric($value) || (int) $value < 1 || ! Congregation::query()->whereKey((int) $value)->exists()) {
                    $fail('La congrégation sélectionnée est invalide.');
                }
            }],
            'event_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:events,id'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(self::SORTS)],
        ];
    }

    /**
     * Noms français des paramètres dans les messages d'erreur.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'search' => 'recherche',
            'status' => 'statut',
            'family_id' => 'famille',
            'congregation_id' => 'congrégation',
            'event_id' => 'événement',
            'from' => 'date de début',
            'to' => 'date de fin',
            'sort' => 'tri',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated  données validées par rules()
     */
    public static function fromValidated(array $validated): self
    {
        $string = static fn (string $key): ?string => isset($validated[$key]) && is_string($validated[$key]) && trim($validated[$key]) !== ''
            ? trim($validated[$key])
            : null;

        $familyId = $validated['family_id'] ?? null;
        $congregation = $validated['congregation_id'] ?? null;
        $withoutCongregation = $congregation === self::NO_CONGREGATION;
        $eventId = $validated['event_id'] ?? null;

        return new self(
            search: $string('search'),
            status: $string('status'),
            familyId: is_numeric($familyId) ? (int) $familyId : null,
            congregationId: ! $withoutCongregation && is_numeric($congregation) ? (int) $congregation : null,
            withoutCongregation: $withoutCongregation,
            eventId: is_numeric($eventId) ? (int) $eventId : null,
            from: $string('from'),
            to: $string('to'),
            sort: $string('sort') ?? self::DEFAULT_SORT,
        );
    }

    /**
     * Requête Eloquent filtrée, triée, avec les agrégats de visites (sans N+1).
     *
     * @return Builder<Visitor>
     */
    public function builder(): Builder
    {
        $query = Visitor::query()->withVisitStats();

        if ($this->search !== null) {
            self::applySearch($query, $this->search);
        }

        if ($this->status === self::STATUS_NON_MEMBER) {
            $query->where('status', '!=', VisitorStatus::Membre->value);
        } elseif ($this->status !== null) {
            $query->where('status', $this->status);
        }

        if ($this->familyId !== null) {
            $familyId = $this->familyId;
            $query->whereHas('visits', function (Builder $visits) use ($familyId): void {
                $visits->where('family_id', $familyId);
            });
        }

        // Visiteurs dont l'invitant appartient à cette congrégation : une colonne du visiteur,
        // donc une simple égalité (aucune jointure, contrairement aux filtres par visite).
        // « aucune » sort ceux qui restent à compléter à la main.
        if ($this->withoutCongregation) {
            $query->whereNull('inviter_congregation_id');
        } elseif ($this->congregationId !== null) {
            $query->where('inviter_congregation_id', $this->congregationId);
        }

        // Visiteurs ayant AU MOINS UNE visite rattachée à cet événement (en pratique la 1re,
        // celle faite depuis le lien dédié) ; se combine en ET avec les autres filtres.
        if ($this->eventId !== null) {
            $eventId = $this->eventId;
            $query->whereHas('visits', function (Builder $visits) use ($eventId): void {
                $visits->where('event_id', $eventId);
            });
        }

        if ($this->from !== null || $this->to !== null) {
            // Date de 1re visite = date de la visite n° 1 (index unique visitor_id, visit_number).
            $from = $this->from;
            $to = $this->to;
            $query->whereHas('visits', function (Builder $visits) use ($from, $to): void {
                $visits->where('visit_number', 1)
                    ->when($from !== null, fn (Builder $q) => $q->where('visit_date', '>=', $from))
                    ->when($to !== null, fn (Builder $q) => $q->where('visit_date', '<=', $to));
            });
        }

        $this->applySort($query);

        return $query;
    }

    /**
     * Recherche sur nom, commune et quartier (LIKE échappé). Si la saisie ressemble à un numéro
     * (« +225 07 00 », « 07-00 »), elle cherche aussi dans le téléphone par ses seuls chiffres :
     * « +225 07 00 » trouve « +2250700… ».
     *
     * @param  Builder<Visitor>  $query
     */
    public static function applySearch(Builder $query, string $search): void
    {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        $like = '%'.self::escapeLike($search).'%';
        $digits = preg_replace('/\D+/', '', $search) ?? '';
        $phoneLike = $digits !== '' && preg_match(self::PHONE_LIKE, $search) === 1;

        $query->where(function (Builder $query) use ($like, $digits, $phoneLike): void {
            $query->where('full_name', 'like', $like)
                ->orWhere('commune', 'like', $like)
                ->orWhere('quartier', 'like', $like);

            if ($phoneLike) {
                $query->orWhere('phone', 'like', '%'.$digits.'%');
            }
        });
    }

    /**
     * Échappe les caractères spéciaux de LIKE (`\` en premier, puis `%` et `_`) ;
     * `\` est le caractère d'échappement par défaut de MariaDB.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Filtres actifs, sans la saisie de recherche (qui peut contenir un nom ou un numéro) :
     * utilisés dans le journal d'audit des exports.
     *
     * @return array<string, bool|int|string>
     */
    public function auditFilters(): array
    {
        return array_filter([
            'search' => $this->search !== null,
            'status' => $this->status,
            'family_id' => $this->familyId,
            'congregation_id' => $this->withoutCongregation ? self::NO_CONGREGATION : $this->congregationId,
            'event_id' => $this->eventId,
            'from' => $this->from,
            'to' => $this->to,
            'sort' => $this->sort,
        ], static fn (mixed $value): bool => $value !== null && $value !== false);
    }

    /**
     * Tri en liste blanche, avec l'identifiant en départage (pagination et lecture par blocs stables).
     *
     * @param  Builder<Visitor>  $query
     */
    private function applySort(Builder $query): void
    {
        match ($this->sort) {
            'created_at' => $query->orderBy('visitors.created_at')->orderBy('visitors.id'),
            'full_name' => $query->orderBy('visitors.full_name')->orderBy('visitors.id'),
            // Alias de l'agrégat ; un visiteur sans visite (NULL) arrive en dernier.
            '-last_visit_date' => $query->orderByDesc('visits_max_visit_date')->orderByDesc('visitors.id'),
            default => $query->orderByDesc('visitors.created_at')->orderByDesc('visitors.id'),
        };
    }
}
