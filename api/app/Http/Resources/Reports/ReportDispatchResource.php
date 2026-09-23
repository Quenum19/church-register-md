<?php

namespace App\Http\Resources\Reports;

use App\Models\ReportDispatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Envoi d'un rapport (contrat §4) : { sent_at (ISO 8601 avec fuseau), recipients: [emails] }.
 */
class ReportDispatchResource extends JsonResource
{
    public function __construct(ReportDispatch $dispatch)
    {
        parent::__construct($dispatch);
    }

    /**
     * @return array{sent_at: string, recipients: list<string>}
     */
    public function toArray(Request $request): array
    {
        /** @var ReportDispatch $dispatch */
        $dispatch = $this->resource;

        return [
            'sent_at' => $dispatch->sent_at->toIso8601String(),
            'recipients' => $dispatch->recipients,
        ];
    }
}
