<?php

namespace App\Notifications\Auth;

/**
 * Liens du SPA envoyés par e-mail (contrat d'API §3) :
 * `{APP_URL}/admin/reinitialiser?token=…&email=…` (+ `&invitation=1` pour une invitation).
 */
final class PasswordLinks
{
    public static function reset(#[\SensitiveParameter] string $token, string $email): string
    {
        return self::build('/admin/reinitialiser', ['token' => $token, 'email' => $email]);
    }

    public static function invitation(#[\SensitiveParameter] string $token, string $email): string
    {
        return self::build('/admin/reinitialiser', ['token' => $token, 'email' => $email, 'invitation' => 1]);
    }

    public static function forgotPassword(): string
    {
        return self::build('/admin/mot-de-passe-oublie');
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private static function build(string $path, array $query = []): string
    {
        $url = rtrim((string) config('app.url'), '/').$path;

        return $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
