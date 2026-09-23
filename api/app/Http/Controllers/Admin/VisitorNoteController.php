<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Visitor;
use App\Models\VisitorNote;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Notes de suivi d'un visiteur (contrat d'API §4).
 */
class VisitorNoteController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * POST /api/admin/visitors/{id}/notes (notes.create) => 201 `{ data: Note }`.
     */
    public function store(StoreNoteRequest $request, Visitor $visitor): JsonResponse
    {
        $note = DB::transaction(function () use ($request, $visitor): VisitorNote {
            $note = VisitorNote::query()->create([
                'visitor_id' => $visitor->id,
                'user_id' => $request->user()?->getAuthIdentifier(),
                'body' => $request->body(),
            ]);

            $this->audit->log('note.created', $note, ['visitor_id' => $visitor->id]);

            return $note;
        });

        return (new NoteResource($note->load('author:id,name')))->response()->setStatusCode(201);
    }

    /**
     * DELETE /api/admin/notes/{id} (auteur ou super_admin, App\Policies\VisitorNotePolicy) => 204.
     */
    public function destroy(VisitorNote $note): Response
    {
        DB::transaction(function () use ($note): void {
            $this->audit->log('note.deleted', $note, ['visitor_id' => $note->visitor_id]);
            $note->delete();
        });

        return response()->noContent();
    }
}
