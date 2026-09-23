<?php

namespace App\Http\Resources;

use App\Models\VisitorNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `Note` du contrat d'API §4 : `{ id, body, author: {id,name}|null, created_at, can_delete }`.
 * `can_delete` applique App\Policies\VisitorNotePolicy (auteur ou super_admin).
 * La relation `author` doit être chargée.
 *
 * @property-read VisitorNote $resource
 */
class NoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $note = $this->resource;

        return [
            'id' => $note->id,
            'body' => $note->body,
            'author' => VisitorSummaryResource::userRef($note->author),
            'created_at' => VisitorSummaryResource::timestamp($note->created_at),
            'can_delete' => $request->user()?->can('delete', $note) ?? false,
        ];
    }
}
