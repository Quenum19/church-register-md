// Constantes métier partagées (libellés affichés, enums alignés sur le serveur).

export const ROLES = ['super_admin', 'moderateur', 'lecteur'] as const
export type Role = (typeof ROLES)[number]
export const ROLE_LABELS: Record<Role, string> = {
  super_admin: 'Super administrateur',
  moderateur: 'Modérateur',
  lecteur: 'Lecteur',
}

export const VISITOR_STATUSES = ['prospect', 'recurrent', 'membre_potentiel', 'membre'] as const
export type VisitorStatus = (typeof VISITOR_STATUSES)[number]
export const STATUS_LABELS: Record<VisitorStatus, string> = {
  prospect: '1re visite',
  recurrent: '2e visite',
  membre_potentiel: 'Membre potentiel',
  membre: 'Membre',
}

export const SOURCES = [
  'invite_membre',
  'saint_esprit',
  'reseaux_sociaux',
  'affiche_tract',
  'bouche_a_oreille',
  'passage',
  'autre',
] as const
export type Source = (typeof SOURCES)[number]
export const SOURCE_LABELS: Record<Source, string> = {
  invite_membre: 'Invité(e) par un membre',
  saint_esprit: 'Saint-Esprit',
  reseaux_sociaux: 'Réseaux sociaux',
  affiche_tract: 'Affiche / tract',
  bouche_a_oreille: 'Bouche-à-oreille',
  passage: "Passage devant l'église",
  autre: 'Autre',
}

export const RETURN_REASONS = ['enseignement', 'chaleur_fraternelle', 'louange_adoration', 'accueil', 'autres'] as const
export type ReturnReason = (typeof RETURN_REASONS)[number]
export const RETURN_REASON_LABELS: Record<ReturnReason, string> = {
  enseignement: "L'enseignement de la Parole",
  chaleur_fraternelle: 'La chaleur fraternelle',
  louange_adoration: "La louange et l'adoration",
  accueil: "L'accueil reçu",
  autres: 'Autre(s) raison(s)',
}

export const VISIT_REASONS = ['nouveau_resident', 'devenir_membre', 'vacances', 'autres'] as const
export type VisitReason = (typeof VISIT_REASONS)[number]
export const VISIT_REASON_LABELS: Record<VisitReason, string> = {
  nouveau_resident: 'Nouveau résident dans la ville',
  devenir_membre: 'Désir de devenir membre',
  vacances: 'De passage / vacances',
  autres: 'Autre raison',
}

export const FAMILY_NAMES = ['Puissance', 'Richesse', 'Sagesse', 'Force', 'Honneur', 'Gloire', 'Louange'] as const

export const MONTH_LABELS = [
  'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
] as const

/* ─── Pays (identification et WhatsApp) ──────────────────────────── */

export interface Country {
  code: CountryCode
  flag: string
  name: string
  dial: string
  /** Nombre de chiffres attendus (hors indicatif), min–max. */
  minDigits: number
  maxDigits: number
  placeholder: string
}

export const COUNTRY_CODES = [
  'CI', 'SN', 'ML', 'BF', 'GH', 'TG', 'BJ', 'GN', 'CM', 'CD', 'GA', 'FR', 'BE', 'US', 'OTHER',
] as const
export type CountryCode = (typeof COUNTRY_CODES)[number]

// Validation indicative côté client ; le serveur fait foi (libphonenumber).
export const COUNTRIES: Country[] = [
  { code: 'CI', flag: '🇨🇮', name: "Côte d'Ivoire", dial: '+225', minDigits: 10, maxDigits: 10, placeholder: '07 00 00 00 00' },
  { code: 'SN', flag: '🇸🇳', name: 'Sénégal', dial: '+221', minDigits: 9, maxDigits: 9, placeholder: '77 000 00 00' },
  { code: 'ML', flag: '🇲🇱', name: 'Mali', dial: '+223', minDigits: 8, maxDigits: 8, placeholder: '70 00 00 00' },
  { code: 'BF', flag: '🇧🇫', name: 'Burkina Faso', dial: '+226', minDigits: 8, maxDigits: 8, placeholder: '70 00 00 00' },
  { code: 'GH', flag: '🇬🇭', name: 'Ghana', dial: '+233', minDigits: 9, maxDigits: 10, placeholder: '024 000 0000' },
  { code: 'TG', flag: '🇹🇬', name: 'Togo', dial: '+228', minDigits: 8, maxDigits: 8, placeholder: '90 00 00 00' },
  { code: 'BJ', flag: '🇧🇯', name: 'Bénin', dial: '+229', minDigits: 8, maxDigits: 10, placeholder: '01 97 00 00 00' },
  { code: 'GN', flag: '🇬🇳', name: 'Guinée', dial: '+224', minDigits: 9, maxDigits: 9, placeholder: '620 00 00 00' },
  { code: 'CM', flag: '🇨🇲', name: 'Cameroun', dial: '+237', minDigits: 9, maxDigits: 9, placeholder: '670 000 000' },
  { code: 'CD', flag: '🇨🇩', name: 'Congo (RDC)', dial: '+243', minDigits: 9, maxDigits: 10, placeholder: '812 345 678' },
  { code: 'GA', flag: '🇬🇦', name: 'Gabon', dial: '+241', minDigits: 7, maxDigits: 9, placeholder: '06 00 00 00' },
  { code: 'FR', flag: '🇫🇷', name: 'France', dial: '+33', minDigits: 9, maxDigits: 10, placeholder: '06 00 00 00 00' },
  { code: 'BE', flag: '🇧🇪', name: 'Belgique', dial: '+32', minDigits: 9, maxDigits: 10, placeholder: '0470 00 00 00' },
  { code: 'US', flag: '🇺🇸', name: 'États-Unis', dial: '+1', minDigits: 10, maxDigits: 10, placeholder: '555 000 0000' },
  { code: 'OTHER', flag: '🌍', name: 'Autre pays', dial: '+', minDigits: 6, maxDigits: 15, placeholder: '+44 7700 900000' },
]

export const DEFAULT_COUNTRY: CountryCode = 'CI'

export function findCountry(code: CountryCode): Country {
  return COUNTRIES.find((c) => c.code === code) ?? COUNTRIES[0]
}

/** Ne garde que les chiffres (et un « + » initial pour OTHER). */
export function sanitizePhone(raw: string, country: CountryCode): string {
  const trimmed = raw.trim()
  const digits = trimmed.replace(/\D/g, '')
  return country === 'OTHER' && trimmed.startsWith('+') ? `+${digits}` : digits
}

export function isPlausiblePhone(raw: string, country: CountryCode): boolean {
  const c = findCountry(country)
  const digits = raw.replace(/\D/g, '')
  return digits.length >= c.minDigits && digits.length <= c.maxDigits
}
