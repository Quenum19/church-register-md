<?php

use App\Enums\Ability;
use App\Enums\ReturnReason;
use App\Enums\Role;
use App\Enums\Source;
use App\Enums\VisitorStatus;
use App\Enums\VisitReason;

it('expose exactement les valeurs du contrat d\'API', function (): void {
    expect(Role::values())->toBe(['super_admin', 'moderateur', 'lecteur'])
        ->and(VisitorStatus::values())->toBe(['prospect', 'recurrent', 'membre_potentiel', 'membre'])
        ->and(Source::values())->toBe([
            'invite_membre', 'saint_esprit', 'reseaux_sociaux', 'affiche_tract', 'bouche_a_oreille', 'passage', 'autre',
        ])
        ->and(ReturnReason::values())->toBe(['enseignement', 'chaleur_fraternelle', 'louange_adoration', 'accueil', 'autres'])
        ->and(VisitReason::values())->toBe(['nouveau_resident', 'devenir_membre', 'vacances', 'autres'])
        ->and(Ability::values())->toBe([
            'visitors.view', 'visitors.update', 'notes.create', 'visitors.convert', 'visitors.unconvert',
            'visitors.delete', 'visitors.export', 'reports.send', 'recipients.manage', 'rotations.manage',
            'settings.update', 'users.manage', 'audit.view',
        ]);
});

it('fournit un libellé français pour chaque valeur', function (): void {
    expect(Source::options())->toMatchArray(['invite_membre' => 'Invité(e) par un membre', 'passage' => "Passage devant l'église"])
        ->and(ReturnReason::Enseignement->label())->toBe("L'enseignement de la Parole")
        ->and(VisitReason::NouveauResident->label())->toBe('Nouveau résident dans la ville')
        ->and(Role::SuperAdmin->label())->toBe('Super administrateur')
        ->and(VisitorStatus::MembrePotentiel->label())->toBe('Membre potentiel');
});

it('attribue les abilities du contrat à chaque rôle', function (): void {
    expect(Role::Lecteur->abilities())->toBe(['visitors.view'])
        ->and(Role::Moderateur->abilities())->toBe(['visitors.view', 'visitors.update', 'notes.create'])
        ->and(Role::SuperAdmin->abilities())->toBe(Ability::values())
        ->and(Role::Moderateur->allows(Ability::NotesCreate))->toBeTrue()
        ->and(Role::Moderateur->allows('visitors.delete'))->toBeFalse();
});

it('déduit le statut du nombre de visites', function (int $count, VisitorStatus $expected): void {
    expect(VisitorStatus::fromVisitCount($count))->toBe($expected);
})->with([
    [0, VisitorStatus::Prospect],
    [1, VisitorStatus::Prospect],
    [2, VisitorStatus::Recurrent],
    [3, VisitorStatus::MembrePotentiel],
]);
