<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Rendu des erreurs au format du contrat d'API §0 : { message, code, errors? }, en français.
 *
 * Reçoit l'exception déjà « préparée » par le gestionnaire Laravel : ModelNotFoundException
 * devient une NotFoundHttpException, AuthorizationException une AccessDeniedHttpException,
 * TokenMismatchException une HttpException 419. Aucun détail technique n'est jamais exposé,
 * sauf en environnement local avec APP_DEBUG=true (page de débogage Laravel).
 */
class ApiExceptionRenderer
{
    public const MESSAGE_SERVER_ERROR = 'Une erreur interne est survenue. Veuillez réessayer plus tard.';

    /** @var array<int, array{0: string, 1: string}> statut => [code, message] */
    private const HTTP_ERRORS = [
        400 => ['bad_request', 'Requête invalide.'],
        401 => ['unauthenticated', 'Non authentifié.'],
        403 => ['forbidden', "Vous n'avez pas l'autorisation d'effectuer cette action."],
        404 => ['not_found', 'Ressource introuvable.'],
        405 => ['method_not_allowed', 'Méthode non autorisée.'],
        409 => ['conflict', "La requête est en conflit avec l'état actuel de la ressource."],
        410 => ['gone', "Cette ressource n'est plus disponible."],
        413 => ['payload_too_large', 'Requête trop volumineuse.'],
        415 => ['unsupported_media_type', 'Format de requête non pris en charge.'],
        419 => ['csrf_expired', 'Votre session a expiré. Rechargez la page puis réessayez.'],
        423 => ['account_locked', 'Compte temporairement verrouillé.'],
        429 => ['too_many_requests', 'Trop de requêtes. Veuillez réessayer dans quelques instants.'],
        503 => ['service_unavailable', 'Service temporairement indisponible. Veuillez réessayer plus tard.'],
    ];

    public function __invoke(Throwable $e, Request $request): JsonResponse|Response|null
    {
        if ($e instanceof HttpResponseException) {
            return null;
        }

        if (! $this->isApiRequest($request)) {
            return $this->renderNonApi($e);
        }

        return match (true) {
            $e instanceof ApiException => $e->render(),
            $e instanceof ValidationException => $this->json(
                $e->getMessage() !== '' ? $e->getMessage() : 'Les données fournies sont invalides.',
                'validation',
                $e->status,
                $e->errors(),
            ),
            $e instanceof AuthenticationException => $this->json(
                $this->customMessage($e->getMessage(), ['Unauthenticated.']) ?? self::HTTP_ERRORS[401][1],
                'unauthenticated',
                401,
            ),
            $e instanceof HttpExceptionInterface => $this->renderHttpException($e),
            default => $this->serverError(),
        };
    }

    public function isApiRequest(Request $request): bool
    {
        return $request->is('api', 'api/*', 'sanctum/*') || $request->expectsJson();
    }

    /**
     * Hors API (pages du SPA) : Laravel rend les erreurs 4xx ; toute erreur serveur reste
     * générique, même si APP_DEBUG est activé par erreur en production.
     */
    private function renderNonApi(Throwable $e): ?Response
    {
        $isClientError = $e instanceof AuthenticationException
            || $e instanceof ValidationException
            || ($e instanceof HttpExceptionInterface && ($e->getStatusCode() < 500 || $e->getStatusCode() === 503));

        if ($isClientError || $this->showsDebugPage()) {
            return null;
        }

        return new Response(self::MESSAGE_SERVER_ERROR, 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function renderHttpException(HttpExceptionInterface $e): ?JsonResponse
    {
        $status = $e->getStatusCode();

        if ($status >= 500 && $status !== 503) {
            return $this->serverError();
        }

        [$code, $message] = self::HTTP_ERRORS[$status] ?? ['http_error', 'La requête ne peut pas être traitée.'];

        // Seul un 403 peut porter un message métier explicite (Gate::deny('…'), Response::deny('…')).
        // Les 404 restent génériques : le message d'origine peut contenir un nom de modèle.
        if ($status === 403) {
            $message = $this->customMessage($e->getMessage(), ['This action is unauthorized.']) ?? $message;
        }

        return $this->json($message, $code, $status, [], $e->getHeaders());
    }

    /**
     * Erreur 500 générique ; null (page de débogage Laravel) en local avec APP_DEBUG=true.
     */
    private function serverError(): ?JsonResponse
    {
        return $this->showsDebugPage() ? null : $this->json(self::MESSAGE_SERVER_ERROR, 'server_error', 500);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $headers
     */
    private function json(string $message, string $code, int $status, array $errors = [], array $headers = []): JsonResponse
    {
        $payload = ['message' => $message, 'code' => $code];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return new JsonResponse($payload, $status, $headers);
    }

    /**
     * Message personnalisé, ou null s'il est vide ou fait partie des messages anglais par défaut.
     *
     * @param  list<string>  $defaults
     */
    private function customMessage(string $message, array $defaults): ?string
    {
        return $message === '' || in_array($message, $defaults, true) ? null : $message;
    }

    /**
     * La page de débogage Laravel n'est affichée qu'en local ET avec APP_DEBUG=true.
     */
    private function showsDebugPage(): bool
    {
        return app()->isLocal() && config('app.debug') === true;
    }
}
