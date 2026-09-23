<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Permissions (abilities) du contrat d'API §1. Chaque valeur est enregistrée comme Gate.
 */
enum Ability: string
{
    use EnumHelpers;

    case VisitorsView = 'visitors.view';
    case VisitorsUpdate = 'visitors.update';
    case NotesCreate = 'notes.create';
    case VisitorsConvert = 'visitors.convert';
    case VisitorsUnconvert = 'visitors.unconvert';
    case VisitorsDelete = 'visitors.delete';
    case VisitorsExport = 'visitors.export';
    case ReportsSend = 'reports.send';
    case RecipientsManage = 'recipients.manage';
    case RotationsManage = 'rotations.manage';
    case SettingsUpdate = 'settings.update';
    case UsersManage = 'users.manage';
    case AuditView = 'audit.view';

    public function label(): string
    {
        return match ($this) {
            self::VisitorsView => 'Consulter les visiteurs, membres, statistiques et rapports',
            self::VisitorsUpdate => 'Modifier le profil des visiteurs',
            self::NotesCreate => 'Ajouter des notes',
            self::VisitorsConvert => 'Convertir un visiteur en membre',
            self::VisitorsUnconvert => 'Annuler une conversion',
            self::VisitorsDelete => 'Supprimer un visiteur',
            self::VisitorsExport => 'Exporter les visiteurs',
            self::ReportsSend => 'Envoyer les rapports',
            self::RecipientsManage => 'Gérer les destinataires des rapports',
            self::RotationsManage => 'Gérer la rotation des familles',
            self::SettingsUpdate => 'Modifier les paramètres',
            self::UsersManage => 'Gérer les administrateurs',
            self::AuditView => "Consulter le journal d'audit",
        };
    }
}
