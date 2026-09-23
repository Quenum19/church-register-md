<?php

namespace App\Mail;

use App\Mail\Concerns\RendersPlainTextAlternative;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * E-mail de vérification de la configuration d'envoi (POST /api/admin/report-recipients/test).
 * Envoyé de façon synchrone pour que l'échec du transport soit signalé immédiatement.
 */
class TestMail extends Mailable
{
    use RendersPlainTextAlternative;

    public function __construct(
        public readonly string $churchName,
        public readonly string $requestedBy,
    ) {
        $this->locale('fr');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "E-mail de test — {$this->churchName}",
            tags: ['test'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.reports.test',
            text: 'mail.reports.test-text',
            with: [
                'title' => "E-mail de test — {$this->churchName}",
                'sentAt' => now()->format('d/m/Y à H:i'),
            ],
        );
    }
}
