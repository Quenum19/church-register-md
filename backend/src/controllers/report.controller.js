const Visitor      = require('../models/Visitor.model');
const Report       = require('../models/Report.model');
const FamilyConfig = require('../models/FamilyConfig.model');
const { sendReportEmail, sendTestEmail } = require('../services/emailService');
const { FAMILIES, getFamilyForMonth }    = require('../utils/familyService');

const DASHBOARD_URL = process.env.DASHBOARD_URL || 'http://localhost:3000/admin';

const MONTH_NAMES = [
  'Janvier','Février','Mars','Avril','Mai','Juin',
  'Juillet','Août','Septembre','Octobre','Novembre','Décembre',
];

/* ─── Génération données rapport ─────────────────────────────────── */
const generateReportData = async (family, month, year) => {
  const startDate = new Date(year, month - 1, 1);
  const endDate   = new Date(year, month,     1);

  const [v1Docs, v2Docs, v3Docs] = await Promise.all([
    Visitor.find({ 'visit1.familleAccueil': family, 'visit1.date': { $gte: startDate, $lt: endDate } }),
    Visitor.find({ 'visit2.familleAccueil': family, 'visit2.date': { $gte: startDate, $lt: endDate } }),
    Visitor.find({ 'visit3.familleAccueil': family, 'visit3.date': { $gte: startDate, $lt: endDate } }),
  ]);

  // Dédupliquer par _id+visite
  const seen = new Set();
  const visitorsMap = [];

  const addVisitors = (docs, visitNumber, visitKey) => {
    docs.forEach(v => {
      const key = `${v._id}-${visitNumber}`;
      if (seen.has(key)) return;
      seen.add(key);
      visitorsMap.push({
        name:        v.visit1?.fullName || v.visit2?.fullName || '—',
        phone:       v.phone,
        visitNumber,
        date:        v[visitKey]?.date,
        status:      v.status,
      });
    });
  };
  addVisitors(v1Docs, 1, 'visit1');
  addVisitors(v2Docs, 2, 'visit2');
  addVisitors(v3Docs, 3, 'visit3');

  const allIds = [...new Set([...v1Docs, ...v2Docs, ...v3Docs].map(v => String(v._id)))];
  const converted = allIds.length > 0
    ? await Visitor.countDocuments({
        _id:    { $in: allIds },
        status: 'membre',
        convertedToMemberAt: { $gte: startDate, $lt: endDate },
      })
    : 0;

  return {
    family,
    month,
    year,
    period: `${MONTH_NAMES[month - 1]} ${year}`,
    stats: {
      totalVisits: v1Docs.length + v2Docs.length + v3Docs.length,
      newVisitors: v1Docs.length,
      returning:   v2Docs.length + v3Docs.length,
      converted,
      byVisit: { v1: v1Docs.length, v2: v2Docs.length, v3: v3Docs.length },
    },
    visitors: visitorsMap.sort((a, b) => new Date(a.date || 0) - new Date(b.date || 0)),
  };
};

/* ─── POST /reports/generate ─────────────────────────────────────── */
exports.generateReport = async (req, res) => {
  try {
    const now    = new Date();
    const month  = parseInt(req.body.month)  || (now.getMonth() === 0 ? 12 : now.getMonth());
    const year   = parseInt(req.body.year)   || (now.getMonth() === 0 ? now.getFullYear() - 1 : now.getFullYear());
    const family = req.body.family           || null;

    const familiesToProcess = family ? [family] : FAMILIES;
    const results = [];

    for (const fam of familiesToProcess) {
      const data   = await generateReportData(fam, month, year);
      const report = await Report.findOneAndUpdate(
        { family: fam, month, year },
        { ...data, generatedBy: req.user?._id, generatedAt: new Date() },
        { upsert: true, new: true, setDefaultsOnInsert: true }
      );
      results.push(report);
    }

    res.json({
      status:  'success',
      message: `${results.length} rapport(s) généré(s) pour ${MONTH_NAMES[month-1]} ${year}.`,
      data:    { reports: results },
    });
  } catch (err) {
    console.error('[generateReport]', err);
    res.status(500).json({ status: 'error', message: `Erreur génération : ${err.message}` });
  }
};

/* ─── POST /reports/send ─────────────────────────────────────────── */
exports.sendReport = async (req, res) => {
  try {
    const { reportId, family, month, year } = req.body;

    const report = reportId
      ? await Report.findById(reportId)
      : await Report.findOne({ family, month: parseInt(month), year: parseInt(year) });

    if (!report) return res.status(404).json({ status: 'error', message: 'Rapport introuvable.' });

    const config = await FamilyConfig.findOne({ family: report.family });
    if (!config || config.recipients.length === 0) {
      return res.status(400).json({
        status:  'error',
        message: `Aucun destinataire configuré pour la famille ${report.family}. Configurez les emails dans Rapports → Configuration.`,
      });
    }

    await sendReportEmail({
      report,
      recipients:   config.recipients,
      dashboardUrl: `${DASHBOARD_URL}#reports`,
    });

    report.emailSentAt = new Date();
    report.emailSentTo = config.recipients.map(r => r.email);
    await report.save();

    res.json({
      status:  'success',
      message: `Rapport envoyé à ${config.recipients.length} destinataire(s) : ${config.recipients.map(r => r.name).join(', ')}.`,
      data:    { report },
    });
  } catch (err) {
    console.error('[sendReport]', err);
    res.status(500).json({ status: 'error', message: `Erreur envoi : ${err.message}` });
  }
};

/* ─── GET /reports ───────────────────────────────────────────────── */
exports.getReports = async (req, res) => {
  try {
    const { family, year, month } = req.query;
    const query = {};
    if (family) query.family = family;
    if (year)   query.year   = parseInt(year);
    if (month)  query.month  = parseInt(month);

    const reports = await Report.find(query).sort({ year: -1, month: -1, family: 1 }).limit(200);
    res.json({ status: 'success', data: { reports } });
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

/* ─── GET /reports/:id ───────────────────────────────────────────── */
exports.getReport = async (req, res) => {
  try {
    const report = await Report.findById(req.params.id).populate('generatedBy', 'firstName lastName');
    if (!report) return res.status(404).json({ status: 'error', message: 'Rapport introuvable.' });
    res.json({ status: 'success', data: { report } });
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

/* ─── GET /reports/family-configs ────────────────────────────────── */
exports.getFamilyConfigs = async (req, res) => {
  try {
    const configs = await FamilyConfig.find();
    // Retourner dans l'ordre exact de rotation
    const result = FAMILIES.map(family => {
      const cfg = configs.find(c => c.family === family);
      return cfg
        ? cfg.toObject()
        : { family, recipients: [], autoReportEnabled: true };
    });
    res.json({ status: 'success', data: { configs: result } });
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

/* ─── PUT /reports/family-configs/:family ───────────────────────── */
exports.saveFamilyConfig = async (req, res) => {
  try {
    const { family } = req.params;
    const { recipients, autoReportEnabled } = req.body;

    if (!FAMILIES.includes(family)) {
      return res.status(400).json({ status: 'error', message: 'Famille invalide.' });
    }
    if (recipients && recipients.length > 5) {
      return res.status(400).json({ status: 'error', message: 'Maximum 5 destinataires.' });
    }

    const config = await FamilyConfig.findOneAndUpdate(
      { family },
      { family, recipients: recipients || [], autoReportEnabled: autoReportEnabled !== false, updatedBy: req.user._id },
      { upsert: true, new: true, runValidators: true }
    );

    res.json({ status: 'success', message: 'Configuration sauvegardée.', data: { config } });
  } catch (err) {
    console.error('[saveFamilyConfig]', err);
    res.status(500).json({ status: 'error', message: `Erreur : ${err.message}` });
  }
};

/* ─── POST /reports/test-email ───────────────────────────────────── */
exports.testEmail = async (req, res) => {
  try {
    const to = req.body.email || process.env.GMAIL_USER;
    await sendTestEmail(to);
    res.json({ status: 'success', message: `Email de test envoyé à ${to}.` });
  } catch (err) {
    console.error('[testEmail]', err);
    res.status(500).json({ status: 'error', message: `Erreur email : ${err.message}. Vérifiez GMAIL_USER et GMAIL_APP_PASSWORD dans .env` });
  }
};

/* ─── Fonction CRON ──────────────────────────────────────────────── */
exports.runMonthlyReports = async () => {
  const now   = new Date();
  const month = now.getMonth() === 0 ? 12 : now.getMonth();
  const year  = now.getMonth() === 0 ? now.getFullYear() - 1 : now.getFullYear();

  console.log(`[CRON] Génération rapports ${MONTH_NAMES[month-1]} ${year}…`);
  const configs = await FamilyConfig.find({ autoReportEnabled: true });

  for (const config of configs) {
    try {
      const data   = await generateReportData(config.family, month, year);
      const report = await Report.findOneAndUpdate(
        { family: config.family, month, year },
        { ...data, generatedAt: new Date() },
        { upsert: true, new: true }
      );
      if (config.recipients.length > 0) {
        await sendReportEmail({ report, recipients: config.recipients, dashboardUrl: `${DASHBOARD_URL}#reports` });
        report.emailSentAt = new Date();
        report.emailSentTo = config.recipients.map(r => r.email);
        await report.save();
        console.log(`[CRON] ✅ ${config.family} → ${config.recipients.length} destinataire(s)`);
      } else {
        console.log(`[CRON] ⚠️  ${config.family} — aucun destinataire`);
      }
    } catch (err) {
      console.error(`[CRON] ❌ ${config.family} :`, err.message);
    }
  }
  console.log('[CRON] ✅ Terminé');
};