/**
 * familyService.backend.js  (CommonJS — pour backend/src/utils/familyService.js)
 *
 * Ordre de rotation officiel :
 * Puissance → Richesse → Sagesse → Force → Honneur → Gloire → Louange
 *
 * Logique : on calcule le mois courant depuis une date d'ancrage connue.
 * Février 2026 = Force (index 3 dans le tableau).
 * On recule pour trouver l'ancre de Puissance (index 0).
 */

const FAMILIES = [
  'Puissance',
  'Richesse',
  'Sagesse',
  'Force',
  'Honneur',
  'Gloire',
  'Louange',
];

// JS months : 0=Jan, 1=Fév, ..., 10=Nov, 11=Déc
// Ancrage : Novembre 2025 = Puissance (index 0) → Fév 2026 = +3 mois = Force ✓
const ANCHOR_YEAR  = 2025;
const ANCHOR_MONTH = 10; // Novembre
const ANCHOR_INDEX = 0;  // Puissance

/**
 * Retourne l'index de famille pour un mois/année donné.
 * @param {number} year
 * @param {number} month - 0-indexed (0 = Janvier)
 */
const getFamilyIndex = (year, month) => {
  const totalMonths  = (year - ANCHOR_YEAR) * 12 + (month - ANCHOR_MONTH);
  const index        = ((ANCHOR_INDEX + totalMonths) % FAMILIES.length + FAMILIES.length) % FAMILIES.length;
  return index;
};

/**
 * Retourne la famille d'accueil du mois courant.
 */
const getCurrentFamily = () => {
  const now = new Date();
  return FAMILIES[getFamilyIndex(now.getFullYear(), now.getMonth())];
};

/**
 * Retourne la famille pour un mois/année spécifique.
 * @param {number} year
 * @param {number} month - 1-indexed (1 = Janvier)
 */
const getFamilyForMonth = (year, month) => {
  return FAMILIES[getFamilyIndex(year, month - 1)];
};

/**
 * Retourne le planning des N prochains mois.
 * @param {number} count
 * @returns {{ month: string, year: number, family: string, isCurrent: boolean }[]}
 */
const getUpcomingSchedule = (count = 6) => {
  const monthNames = [
    'Janvier','Février','Mars','Avril','Mai','Juin',
    'Juillet','Août','Septembre','Octobre','Novembre','Décembre',
  ];
  const now     = new Date();
  const result  = [];

  for (let i = 0; i < count; i++) {
    const date   = new Date(now.getFullYear(), now.getMonth() + i, 1);
    const year   = date.getFullYear();
    const month  = date.getMonth();
    const family = FAMILIES[getFamilyIndex(year, month)];
    result.push({
      month:     monthNames[month],
      year,
      family,
      isCurrent: i === 0,
    });
  }
  return result;
};

// Vérification au démarrage
console.log('[familyService] Famille actuelle :', getCurrentFamily());
// Doit afficher "Force" en Février 2026

module.exports = { FAMILIES, getCurrentFamily, getFamilyForMonth, getUpcomingSchedule };