<?php

namespace App\Services\Journey;

use App\Enums\VisitorStatus;
use App\Exceptions\ApiException;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\FamilyRotationService;
use App\Services\VisitorStatusService;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;

/**
 * Enregistrement d'une visite du parcours public (POST /api/public/visits, contrat §2, étapes 1, 5 et 6).
 *
 * Garanties : une visite par jour et par visiteur, une seule visite par numéro (1 à 3),
 * idempotence par `idempotency_key`. Elles reposent sur les index uniques de la base ;
 * les vérifications applicatives ne servent qu'à répondre avec le bon code. Toute violation
 * d'unicité devient une 409 (ou un rejeu 200), jamais une 500.
 */
class VisitRecorder
{
    use DetectsConcurrencyErrors;

    /** Tentatives de la transaction en cas d'interblocage MariaDB. */
    public const TRANSACTION_ATTEMPTS = 3;

    /** Index uniques (noms générés par les migrations) qui arbitrent les requêtes concurrentes. */
    public const INDEX_VISIT_DATE = 'visits_visitor_id_visit_date_unique';

    public const INDEX_VISIT_NUMBER = 'visits_visitor_id_visit_number_unique';

    public const INDEX_IDEMPOTENCY_KEY = 'visits_idempotency_key_unique';

    public const INDEX_VISITOR_PHONE = 'visitors_phone_unique';

    private const UNIQUE_INDEXES = [
        self::INDEX_VISIT_DATE,
        self::INDEX_VISIT_NUMBER,
        self::INDEX_IDEMPOTENCY_KEY,
        self::INDEX_VISITOR_PHONE,
    ];

    public function __construct(
        private readonly VisitTokenService $tokens,
        private readonly FamilyRotationService $rotations,
        private readonly VisitorStatusService $statuses,
    ) {}

    /**
     * Rejeu idempotent (étape 1 du contrat), avant toute vérification du jeton :
     * - aucune visite avec cette clé : null ;
     * - visite du même numéro que le jeton (déchiffré en ignorant l'expiration et l'usage) : rejeu ;
     * - sinon (autre numéro, jeton indéchiffrable) : 409 idempotency_conflict.
     */
    public function replay(string $idempotencyKey, string $sessionToken): ?RecordedVisit
    {
        $visit = $this->findByKey($idempotencyKey);

        if ($visit === null) {
            return null;
        }

        try {
            $token = $this->tokens->decode($sessionToken, ignoreExpiry: true);
        } catch (ApiException) {
            throw JourneyErrors::idempotencyConflict();
        }

        return $this->replayFor($visit, $token);
    }

    /**
     * Enregistre la visite de l'étape du jeton, dans une transaction (étape 5 du contrat).
     */
    public function record(VisitToken $token, string $idempotencyKey, VisitSubmission $submission): RecordedVisit
    {
        try {
            return DB::transaction(
                fn (): RecordedVisit => $this->insert($token, $idempotencyKey, $submission),
                self::TRANSACTION_ATTEMPTS,
            );
        } catch (UniqueConstraintViolationException $e) {
            // Requête concurrente arrivée entre les vérifications et l'insertion.
            return $this->resolveConflict($token, $idempotencyKey, $this->violatedIndex($e));
        } catch (PDOException $e) {
            // Interblocage persistant malgré les nouvelles tentatives : même traitement qu'un conflit.
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }

            return $this->resolveConflict($token, $idempotencyKey, null);
        }
    }

    private function insert(VisitToken $token, string $idempotencyKey, VisitSubmission $submission): RecordedVisit
    {
        $visitor = $this->findVisitor($token);

        // Double vérification sous verrou : une requête jumelle (même clé) a pu aboutir entre-temps.
        $existing = $this->findByKey($idempotencyKey);

        if ($existing !== null) {
            return $this->replayFor($existing, $token);
        }

        if ($token->step === 1) {
            // Étape 1 : le visiteur ne doit pas exister (le nom n'est jamais modifiable depuis le public).
            if ($visitor !== null) {
                throw JourneyErrors::stepMismatch();
            }

            $visitor = Visitor::query()->create([
                ...$submission->profile,
                'phone' => $token->phoneE164,
                'consent_at' => now(),
            ]);
        } elseif (
            $visitor === null
            || $visitor->status === VisitorStatus::Membre
            || $visitor->visits()->count() + 1 !== $token->step
        ) {
            throw JourneyErrors::stepMismatch();
        }

        $now = JourneyClock::now();
        $family = $this->rotations->familyFor($now);

        if ($family === null) {
            Log::warning('Parcours visiteur : aucune rotation de famille pour ce mois, visite enregistrée sans famille.', [
                'year' => $now->year,
                'month' => $now->month,
            ]);
        }

        Visit::query()->create([
            'visitor_id' => $visitor->id,
            'visit_number' => $token->step,
            'visit_date' => $now->toDateString(),
            'family_id' => $family?->id,
            'answers' => $submission->answers,
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->statuses->refresh($visitor);

        // Usage unique, de façon atomique : une requête concurrente avec le même jeton échoue ici.
        if (! $this->tokens->markConsumed($token)) {
            throw JourneyErrors::tokenUsed();
        }

        return new RecordedVisit($token->step, $family, true);
    }

    /**
     * Visiteur du jeton, verrouillé (FOR UPDATE) pour les étapes 2 et 3.
     *
     * À l'étape 1, le visiteur n'existe normalement pas : un FOR UPDATE ne verrouillerait qu'un
     * intervalle d'index (gap lock), source d'interblocages entre deux nouveaux visiteurs aux
     * numéros voisins. L'index unique sur `visitors.phone` suffit à arbitrer deux créations.
     */
    private function findVisitor(VisitToken $token): ?Visitor
    {
        $query = Visitor::query()->where('phone', $token->phoneE164);

        return $token->step === 1 ? $query->first() : $query->lockForUpdate()->first();
    }

    private function findByKey(string $idempotencyKey): ?Visit
    {
        return Visit::query()
            ->select(['id', 'visitor_id', 'visit_number', 'family_id'])
            ->with(['visitor:id,phone', 'family:id,name'])
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function replayFor(Visit $visit, VisitToken $token): RecordedVisit
    {
        if ($visit->visitor->phone !== $token->phoneE164) {
            throw JourneyErrors::idempotencyConflict();
        }

        return RecordedVisit::replayOf($visit);
    }

    /**
     * Violation d'unicité (ou interblocage) : rejeu si la visite jumelle existe, sinon 409 selon l'index.
     */
    private function resolveConflict(VisitToken $token, string $idempotencyKey, ?string $index): RecordedVisit
    {
        $existing = $this->findByKey($idempotencyKey);

        if ($existing !== null) {
            return $this->replayFor($existing, $token);
        }

        throw match ($index) {
            self::INDEX_VISIT_DATE => JourneyErrors::alreadyToday(),
            self::INDEX_IDEMPOTENCY_KEY => JourneyErrors::idempotencyConflict(),
            // (visitor_id, visit_number), visitors.phone ou interblocage : le parcours a changé.
            default => JourneyErrors::stepMismatch(),
        };
    }

    /**
     * Index unique violé, cherché dans le message du pilote (« Duplicate entry '…' for key '…' »,
     * traduit selon la langue du serveur MariaDB : on ne cherche donc que le nom de l'index).
     * La valeur dupliquée citée n'est jamais du texte libre (identifiants, date, E.164, UUID).
     */
    private function violatedIndex(UniqueConstraintViolationException $e): ?string
    {
        $message = $e->errorInfo[2] ?? $e->getPrevious()?->getMessage();

        if (! is_string($message)) {
            return null;
        }

        foreach (self::UNIQUE_INDEXES as $index) {
            if (str_contains($message, $index)) {
                return $index;
            }
        }

        return null;
    }
}
