<?php

namespace App\Http\Requests\Admin;

/**
 * PATCH /api/admin/events/{id} (events.manage) : tous les champs sont facultatifs
 * (`{ name?, slug?, event_date?, active? }`), le slug de l'événement modifié ne peut pas
 * entrer en conflit avec lui-même.
 */
class UpdateEventRequest extends EventRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->fieldRules(['sometimes', 'required']);
    }
}
