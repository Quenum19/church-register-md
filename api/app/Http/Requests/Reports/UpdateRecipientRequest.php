<?php

namespace App\Http\Requests\Reports;

use App\Models\ReportRecipient;

/**
 * PATCH /api/admin/report-recipients/{recipient} — mêmes champs que la création, tous optionnels.
 */
class UpdateRecipientRequest extends RecipientRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'family_id' => ['sometimes', ...$this->familyRules()],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', ...$this->emailRules()],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function current(): ?ReportRecipient
    {
        $recipient = $this->route('recipient');

        return $recipient instanceof ReportRecipient ? $recipient : null;
    }
}
