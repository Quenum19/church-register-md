<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne du journal d'audit (contrat d'API §4) :
 * { id, action, user: {id, name}|null, subject_type, subject_id, ip, created_at, meta }.
 *
 * La relation `user` doit être chargée (with('user:id,name')). `subject_type` est l'alias de la
 * morph map (AppServiceProvider::MORPH_MAP), déjà conforme au contrat : exposé tel quel.
 *
 * @property AuditLog $resource
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $log = $this->resource;
        $user = $log->user;

        return [
            'id' => $log->id,
            'action' => $log->action,
            'user' => $user === null ? null : ['id' => $user->id, 'name' => $user->name],
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'ip' => $log->ip,
            'created_at' => $log->created_at?->toIso8601String(),
            'meta' => $log->meta === null || $log->meta === [] ? null : $log->meta,
        ];
    }
}
