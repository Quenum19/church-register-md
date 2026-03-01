/**
 * cronJobs.js
 * Lancer depuis server.js : require('./src/cron/cronJobs');
 *
 * npm install node-cron
 */

const cron = require('node-cron');
const { runMonthlyReports } = require('../controllers/report.controller');

/**
 * Tous les 1ers du mois à 08h00 (heure serveur)
 * Format : seconde minute heure jour mois jour-semaine
 */
cron.schedule('0 0 8 1 * *', async () => {
  console.log('[CRON] 🕗 Déclenchement rapport mensuel automatique…');
  try {
    await runMonthlyReports();
  } catch (err) {
    console.error('[CRON] ❌ Erreur critique :', err.message);
  }
}, {
  scheduled: true,
  timezone:  'Africa/Abidjan',   // UTC+0 (Côte d'Ivoire)
});

console.log('[CRON] ✅ Planificateur initialisé — rapport le 1er de chaque mois à 08h00');