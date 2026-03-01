const express = require('express');
const router  = express.Router();

const {
  identify,
  visit1, visit2, visit3,
  getAll, getOne,
  update, convertToMember, delete: deleteVisitor,
  getStats,
} = require('../controllers/visitor.controller');

const { protect, restrictTo } = require('../middleware/auth.middleware');

// ── PUBLIC (formulaires via QR code) ──────────────────────────────
router.post('/identify', identify);
router.post('/visit1',   visit1);
router.post('/visit2',   visit2);
router.post('/visit3',   visit3);

// ── PROTÉGÉES (dashboard admin) ───────────────────────────────────
router.use(protect);

router.get('/stats',        restrictTo('super_admin', 'moderateur', 'lecteur'), getStats);
router.get('/',             restrictTo('super_admin', 'moderateur', 'lecteur'), getAll);
router.get('/:id',          restrictTo('super_admin', 'moderateur', 'lecteur'), getOne);
router.patch('/:id',        restrictTo('super_admin', 'moderateur'),            update);
router.patch('/:id/convert',restrictTo('super_admin'),                          convertToMember);
router.delete('/:id',       restrictTo('super_admin'),                          deleteVisitor);

module.exports = router;