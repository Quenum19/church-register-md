<?php

namespace App\Http\Requests\Reports;

use App\Models\ReportRecipient;

/**
 * POST /api/admin/report-recipients — { family_id|null, name ≤100, email, active? }.
 * `family_id` est obligatoire (null = destinataire global, explicitement) ; `active` vaut true par défaut.
 */
class StoreRecipientRequest extends RecipientRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'family_id' => ['present', ...$this->familyRules()],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', ...$this->emailRules()],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function current(): ?ReportRecipient
    {
        return null;
    }
}
