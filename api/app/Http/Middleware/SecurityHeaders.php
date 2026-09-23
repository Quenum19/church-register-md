<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité (contrat d'API §5), appliqués à toutes les réponses :
 * API, SPA (route de repli) et pages d'erreur.
 */
class SecurityHeaders
{
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; "
        ."base-uri 'self'; form-action 'self'; object-src 'none'";

    public const STRICT_TRANSPORT_SECURITY = 'max-age=31536000; includeSubDomains';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // HSTS uniquement en production et sur une requête HTTPS (sinon le navigateur l'ignore
        // ou, pire, on verrouillerait un environnement de dev en HTTPS).
        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', self::STRICT_TRANSPORT_SECURITY);
        }

        return $response;
    }
}
