<?php

namespace App\Services\Journey;

use App\Enums\PhoneCountry;
use App\Exceptions\ApiException;
use App\Models\Visit;
use App\Models\Visitor;
use App\Support\RateLimits;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Identification d'un numéro (POST /api/public/identify, contrat §2).
 *
 * La réponse a toujours la même forme et ne contient aucune donnée personnelle :
 * seulement l'étape suivante et, pour les étapes 1 à 3, un jeton de parcours opaque.
 *
 * Limite par numéro (5/heure) appliquée ici et non par le middleware : seules les
 * identifications qui DÉLIVRENT un jeton sont comptées. Une personne déjà enregistrée
 * aujourd'hui, ou ayant terminé son parcours, ne consomme donc pas son propre quota.
 */
class IdentificationService
{
    public const STEP_COMPLETE = 'complete';

    public const STEP_DONE_TODAY = 'done_today';

    public function __construct(
        private readonly VisitTokenService $tokens,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * @return array{step: int|string, session_token: string|null, expires_in: int|null}
     *
     * @throws ApiException 429 si ce numéro a déjà obtenu 5 jetons dans l'heure
     */
    public function identify(string $phoneE164, PhoneCountry $country): array
    {
        $key = RateLimits::identifyNumberKey($phoneE164);

        if ($this->limiter->tooManyAttempts($key, RateLimits::IDENTIFY_PER_HOUR)) {
            throw JourneyErrors::tooManyIdentifications($this->limiter->availableIn($key));
        }

        $step = $this->stepFor($phoneE164);

        if (is_int($step)) {
            $this->limiter->hit($key, RateLimits::IDENTIFY_WINDOW_SECONDS);

            return [
                'step' => $step,
                'session_token' => $this->tokens->issue($phoneE164, $country, $step),
                'expires_in' => VisitTokenService::TTL_SECONDS,
            ];
        }

        return ['step' => $step, 'session_token' => null, 'expires_in' => null];
    }

    /**
     * Étape suivante d'un numéro E.164 :
     * - 1 : numéro inconnu ; 2 ou 3 : prochaine visite ;
     * - « done_today » : une visite existe déjà aujourd'hui (prioritaire : la personne vient
     *   de s'enregistrer, y compris pour sa 3e visite) ;
     * - « complete » : 3 visites déjà faites, ou visiteur converti en membre.
     *
     * @return int|'complete'|'done_today'
     */
    public function stepFor(string $phoneE164): int|string
    {
        $today = JourneyClock::today();

        $visitor = Visitor::query()
            ->select('id')
            ->where('phone', $phoneE164)
            ->withCount('visits')
            ->withExists([
                'member',
                'visits as has_visit_today' => fn (Builder $query) => $query->where('visit_date', $today),
            ])
            ->first();

        if ($visitor === null) {
            return 1;
        }

        if ((bool) $visitor->getAttribute('has_visit_today')) {
            return self::STEP_DONE_TODAY;
        }

        $visits = (int) $visitor->getAttribute('visits_count');

        if ((bool) $visitor->getAttribute('member_exists') || $visits >= Visit::MAX_VISITS) {
            return self::STEP_COMPLETE;
        }

        return $visits + 1;
    }
}
