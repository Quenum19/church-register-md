<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Models\VisitorNote;
use Tests\Feature\Admin\Support\AdminFixtures;

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    AdminFixtures::families();
    $this->visitor = AdminFixtures::visitor(['2026-09-01']);
    $this->author = User::factory()->moderateur()->create(['name' => 'Marie Modératrice']);
});

describe('POST /visitors/{id}/notes', function (): void {
    it('crée une note (201) au format du contrat et la journalise', function (): void {
        $response = $this->actingAs($this->author)
            ->postJson("/api/admin/visitors/{$this->visitor->id}/notes", ['body' => "  Appelé(e) ce jour.\nTrès bon accueil.  "])
            ->assertCreated();

        $note = VisitorNote::query()->sole();

        $response->assertExactJson(['data' => [
            'id' => $note->id,
            'body' => "Appelé(e) ce jour.\nTrès bon accueil.",
            'author' => ['id' => $this->author->id, 'name' => 'Marie Modératrice'],
            'created_at' => '2026-09-22T10:00:00+00:00',
            'can_delete' => true,
        ]]);

        $log = AuditLog::query()->sole();

        expect($note->visitor_id)->toBe($this->visitor->id)
            ->and($note->user_id)->toBe($this->author->id)
            ->and($log->action)->toBe('note.created')
            ->and($log->subject_type)->toBe('note')
            ->and($log->subject_id)->toBe($note->id)
            ->and($log->meta)->toBe(['visitor_id' => $this->visitor->id]);
    });

    it('accepte 2 000 caractères et refuse au-delà (422)', function (): void {
        $this->actingAs($this->author);

        $this->postJson("/api/admin/visitors/{$this->visitor->id}/notes", ['body' => str_repeat('é', 2000)])->assertCreated();
        $this->postJson("/api/admin/visitors/{$this->visitor->id}/notes", ['body' => str_repeat('é', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    });

    it('refuse une note vide ou invalide (422)', function (mixed $body): void {
        $this->actingAs($this->author)
            ->postJson("/api/admin/visitors/{$this->visitor->id}/notes", ['body' => $body])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);

        expect(VisitorNote::query()->count())->toBe(0);
    })->with([
        'vide' => [''],
        'espaces' => ['   '],
        'null' => [null],
        'tableau' => [['a']],
    ]);

    it('répond 404 pour un visiteur inexistant', function (): void {
        $this->actingAs($this->author)
            ->postJson('/api/admin/visitors/999999/notes', ['body' => 'Test'])
            ->assertNotFound();
    });
});

describe('DELETE /notes/{id}', function (): void {
    beforeEach(function (): void {
        $this->note = VisitorNote::factory()->for($this->visitor)->for($this->author, 'author')->create();
    });

    it('laisse l\'auteur supprimer sa note et journalise', function (): void {
        $this->actingAs($this->author)->deleteJson("/api/admin/notes/{$this->note->id}")->assertNoContent();

        $log = AuditLog::query()->sole();

        expect(VisitorNote::query()->count())->toBe(0)
            ->and($log->action)->toBe('note.deleted')
            ->and($log->subject_type)->toBe('note')
            ->and($log->subject_id)->toBe($this->note->id)
            ->and($log->user_id)->toBe($this->author->id)
            ->and($log->meta)->toBe(['visitor_id' => $this->visitor->id]);
    });

    it('refuse un autre modérateur (403)', function (): void {
        $this->actingAs(User::factory()->moderateur()->create())
            ->deleteJson("/api/admin/notes/{$this->note->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('message', 'Seul l\'auteur de la note ou un super administrateur peut la supprimer.');

        expect(VisitorNote::query()->count())->toBe(1)->and(AuditLog::query()->count())->toBe(0);
    });

    it('laisse un super_admin supprimer la note d\'un autre', function (): void {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->deleteJson("/api/admin/notes/{$this->note->id}")
            ->assertNoContent();

        expect(VisitorNote::query()->count())->toBe(0)->and(AuditLog::query()->sole()->action)->toBe('note.deleted');
    });

    it('refuse la suppression d\'une note sans auteur à un modérateur', function (): void {
        $orphan = VisitorNote::factory()->for($this->visitor)->create(['user_id' => null]);

        $this->actingAs($this->author)->deleteJson("/api/admin/notes/{$orphan->id}")->assertForbidden();
    });

    it('répond 404 pour une note inexistante ou un identifiant invalide', function (string $id): void {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->deleteJson("/api/admin/notes/{$id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    })->with(['999999', 'abc']);
});
