<?php

namespace App\Http\Requests\Admin;

/**
 * POST /api/admin/events (events.manage) : `{ name, slug, event_date|null, active }`.
 * Slug déjà pris ou mal formé => 422 sur `slug`.
 */
class StoreEventRequest extends EventRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->fieldRules(['required']);
    }
}
