<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Erreur métier rendue au format du contrat : { message, code } avec le statut HTTP voulu.
 *
 * Exemple : throw new ApiException('Une visite existe déjà aujourd\'hui.', 'already_today', 409);
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 400,
        public readonly array $errors = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $payload = ['message' => $this->getMessage(), 'code' => $this->errorCode];

        if ($this->errors !== []) {
            $payload['errors'] = $this->errors;
        }

        return new JsonResponse($payload, $this->status, $this->headers);
    }
}
