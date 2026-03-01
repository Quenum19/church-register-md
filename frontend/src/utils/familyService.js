/**
 * familyService.frontend.js  (ESM — pour frontend/src/utils/familyService.js)
 *
 * Même logique que la version backend.
 * Ordre : Puissance → Richesse → Sagesse → Force → Honneur → Gloire → Louange
 * Ancrage : Novembre 2025 = Puissance (index 0) → Février 2026 = Force (index 3) ✓
 */

export const FAMILIES = [
  'Puissance',
  'Richesse',
  'Sagesse',
  'Force',
  'Honneur',
  'Gloire',
  'Louange',
];

const ANCHOR_YEAR  = 2025;
const ANCHOR_MONTH = 10; // Novembre = index 10 en 0-based
const ANCHOR_INDEX = 0;

const getFamilyIndex = (year, month) => {
  const totalMonths = (year - ANCHOR_YEAR) * 12 + (month - ANCHOR_MONTH);
  return ((ANCHOR_INDEX + totalMonths) % FAMILIES.length + FAMILIES.length) % FAMILIES.length;
};

export const getCurrentFamily = () => {
  const now = new Date();
  return FAMILIES[getFamilyIndex(now.getFullYear(), now.getMonth())];
};

export const getFamilyForMonth = (year, month) =>
  FAMILIES[getFamilyIndex(year, month - 1)];

export const getUpcomingSchedule = (count = 6) => {
  const monthNames = [
    'Janvier','Février','Mars','Avril','Mai','Juin',
    'Juillet','Août','Septembre','Octobre','Novembre','Décembre',
  ];
  const now    = new Date();
  const result = [];
  for (let i = 0; i < count; i++) {
    const date   = new Date(now.getFullYear(), now.getMonth() + i, 1);
    const year   = date.getFullYear();
    const month  = date.getMonth();
    result.push({
      month:     monthNames[month],
      year,
      family:    FAMILIES[getFamilyIndex(year, month)],
      isCurrent: i === 0,
    });
  }
  return result;
};