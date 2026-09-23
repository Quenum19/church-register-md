<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\RouteGroups;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Borne APPLICATIVE du corps des requêtes publiques (contrat d'API §2) : taille en octets,
 * profondeur du JSON et longueur des chaînes.
 *
 * Volontairement indépendante de la configuration PHP (`post_max_size`, `memory_limit`,
 * `max_input_*`) : l'hébergement mutualisé ne la garantit pas et elle ne protège ni de la
 * profondeur du JSON ni du coût du parcours du corps.
 *
 * Déclarée tout en haut de la pile globale (bootstrap/app.php) et non dans le groupe
 * « public » : elle doit passer AVANT TrimStrings, qui parcourt tout le corps (mesuré :
 * ~1,3 s pour 2,5 Mo, ~25 s pour 10 Mo). Le routage n'ayant pas encore eu lieu, le périmètre
 * est déterminé par le préfixe d'URL.
 *
 * Au-delà : 413 `payload_too_large`, avec UN seul message (aucune amplification).
 */
class LimitPublicPayload
{
    /** Taille maximale du corps d'une requête publique (256 Kio). */
    public const MAX_BYTES = 262144;

    /** Profondeur maximale des structures JSON (`answers.whatsapp.number` = 3). */
    public const MAX_DEPTH = 8;

    /** Longueur maximale d'une chaîne (le jeton de parcours en fait environ 300). */
    public const MAX_STRING_LENGTH = 4096;

    public const MESSAGE = 'La requête envoyée est trop volumineuse.';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isPublic($request)) {
            if (strlen($request->getContent()) > self::MAX_BYTES) {
                throw self::tooLarge();
            }

            $this->inspect($request->all(), 1);
        }

        return $next($request);
    }

    /**
     * Périmètre : l'API publique (non authentifiée). Les routes admin sont protégées par la
     * session et par la limite `admin`, et la route de repli du SPA n'a pas de corps.
     */
    private function isPublic(Request $request): bool
    {
        return $request->is(RouteGroups::PUBLIC_PREFIX, RouteGroups::PUBLIC_PREFIX.'/*');
    }

    /**
     * Parcours borné du corps décodé : la taille en octets étant déjà bornée, ce parcours
     * coûte au plus un temps constant.
     *
     * @param  array<mixed>  $values
     */
    private function inspect(array $values, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw self::tooLarge();
        }

        foreach ($values as $value) {
            if (is_array($value)) {
                $this->inspect($value, $depth + 1);
            } elseif (is_string($value) && strlen($value) > self::MAX_STRING_LENGTH) {
                throw self::tooLarge();
            }
        }
    }

    private static function tooLarge(): ApiException
    {
        return new ApiException(self::MESSAGE, 'payload_too_large', 413);
    }
}
