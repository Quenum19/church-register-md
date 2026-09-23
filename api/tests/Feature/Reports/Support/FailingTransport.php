<?php

namespace Tests\Feature\Reports\Support;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Transport qui échoue toujours, comme l'API Brevo indisponible ou une clé refusée.
 * Le message contient un détail « technique » qui ne doit jamais atteindre le client HTTP.
 */
final class FailingTransport extends AbstractTransport
{
    public const DETAIL = 'Brevo API 401: Key not found (detail-technique-secret)';

    public int $attempts = 0;

    protected function doSend(SentMessage $message): void
    {
        $this->attempts++;

        throw new TransportException(self::DETAIL);
    }

    public function __toString(): string
    {
        return 'failing://';
    }
}
