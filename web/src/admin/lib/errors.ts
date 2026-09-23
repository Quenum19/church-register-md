import { ApiError } from '../../shared/http'
import type { ApiErrorCodeName } from '../../shared/api-types'
import { formatNumber } from './format'
import { PDF_EXPORT_MAX_ROWS } from './visitorFilters'

// Messages explicites pour les codes métier du contrat (le message serveur sert de repli).
// Les erreurs 5xx arrivent avec un message générique (voir shared/http.ts) : seul le code
// permet alors d'expliquer la cause (ex. 503 `mail_failed`).
const CODE_MESSAGES: Partial<Record<ApiErrorCodeName, string>> = {
  bad_request: 'Requête invalide. Rechargez la page puis réessayez.',
  forbidden: "Vous n'avez pas les droits nécessaires pour cette action.",
  not_found: 'Élément introuvable. Il a peut-être été supprimé.',
  // Comptes administrateurs
  forbidden_self_change:
    'Vous ne pouvez pas modifier votre propre rôle, vous désactiver ni supprimer votre propre compte.',
  last_super_admin: 'Action impossible : il doit toujours rester au moins un super administrateur actif.',
  invitation_not_pending:
    "Ce compte a déjà défini son mot de passe : il n'y a plus d'invitation à renvoyer. En cas d'oubli, la personne peut utiliser « Mot de passe oublié ».",
  // Double authentification
  two_factor_already_enabled: 'La double authentification est déjà activée sur ce compte.',
  two_factor_expired: 'La vérification a expiré. Merci de saisir à nouveau vos identifiants.',
  // Visiteurs
  not_eligible: 'Seul un visiteur au statut « Membre potentiel » (3 visites) peut être converti en membre.',
  already_member: 'Ce visiteur est déjà membre.',
  not_member: "Ce visiteur n'est pas membre : la conversion a peut-être déjà été annulée. Rechargez la fiche.",
  too_many_rows: `Plus de ${formatNumber(PDF_EXPORT_MAX_ROWS)} lignes : l'export PDF n'est pas disponible. Utilisez CSV ou Excel, ou affinez les filtres.`,
  // Rapports et e-mails
  no_recipients:
    "Aucun destinataire actif n'est configuré pour ce rapport. Ajoutez-en dans Paramètres › Destinataires des rapports.",
  already_sent: 'Ce rapport a déjà été envoyé.',
  send_in_progress:
    'Un envoi de ce rapport est déjà en cours. Patientez quelques instants puis rechargez la page pour vérifier qu’il est bien parti.',
  mail_failed:
    "L'envoi de l'e-mail a échoué : le service d'envoi ne répond pas. Rien n'a été envoyé ; réessayez dans quelques minutes.",
}

export function errorMessage(error: unknown, overrides: Record<string, string> = {}): string {
  if (error instanceof ApiError) {
    return overrides[error.code] ?? CODE_MESSAGES[error.code as ApiErrorCodeName] ?? error.message
  }
  return 'Une erreur inattendue est survenue. Merci de réessayer.'
}

export function isApiError(error: unknown, status?: number, code?: ApiErrorCodeName): error is ApiError {
  if (!(error instanceof ApiError)) return false
  if (status !== undefined && error.status !== status) return false
  if (code !== undefined && error.code !== code) return false
  return true
}
