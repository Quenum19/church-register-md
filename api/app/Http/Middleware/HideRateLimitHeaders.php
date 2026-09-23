<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retire les en-têtes `X-RateLimit-*` des réponses de certaines routes.
 *
 * Sur l'identification publique, ces en-têtes renseignent gratuitement un attaquant sur l'état
 * exact des compteurs (combien d'essais restants, pour quelle clé) : ils sont donc masqués.
 * `Retry-After` est conservé : il est utile au client légitime et ne révèle aucun compteur.
 *
 * Doit être déclaré AVANT les middlewares `throttle:*` du groupe : la post-exécution se fait
 * en ordre inverse, ce middleware nettoie donc les en-têtes que les limiteurs viennent d'ajouter.
 */
class HideRateLimitHeaders
{
    /** Noms de routes dont les réponses ne divulguent pas l'état des compteurs de débit. */
    public const ROUTES = ['public.identify'];

    /** @var list<string> */
    private const HEADERS = ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->route()?->named(...self::ROUTES) === true) {
            foreach (self::HEADERS as $header) {
                $response->headers->remove($header);
            }
        }

        return $response;
    }
}
