const express = require('express');
const router  = express.Router();
const {
  generateReport,
  sendReport,
  getReports,
  getReport,
  getFamilyConfigs,
  saveFamilyConfig,
  testEmail,
} = require('../controllers/report.controller');
const { protect, restrictTo } = require('../middleware/auth.middleware');

router.use(protect);

// ── Routes statiques EN PREMIER (avant /:id) ─────────────────────
router.get( '/family-configs',         restrictTo('super_admin','moderateur','lecteur'), getFamilyConfigs);
router.put( '/family-configs/:family', restrictTo('super_admin'),                        saveFamilyConfig);
router.post('/generate',               restrictTo('super_admin','moderateur'),            generateReport);
router.post('/send',                   restrictTo('super_admin'),                         sendReport);
router.post('/test-email',             restrictTo('super_admin'),                         testEmail);

// ── Routes dynamiques EN DERNIER ─────────────────────────────────
router.get('/',    restrictTo('super_admin','moderateur','lecteur'), getReports);
router.get('/:id', restrictTo('super_admin','moderateur','lecteur'), getReport);

module.exports = router;