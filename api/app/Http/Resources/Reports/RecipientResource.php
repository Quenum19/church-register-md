<?php

namespace App\Http\Resources\Reports;

use App\Models\ReportRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Destinataire des rapports (contrat §4) : { id, family: {id,name}|null, name, email, active }.
 * `family` null = destinataire global (reçoit tous les rapports). Relation `family` à précharger.
 */
class RecipientResource extends JsonResource
{
    public function __construct(ReportRecipient $recipient)
    {
        parent::__construct($recipient);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ReportRecipient $recipient */
        $recipient = $this->resource;
        $family = $recipient->family;

        return [
            'id' => $recipient->id,
            'family' => $family !== null ? ['id' => $family->id, 'name' => $family->name] : null,
            'name' => $recipient->name,
            'email' => $recipient->email,
            'active' => $recipient->active,
        ];
    }
}
