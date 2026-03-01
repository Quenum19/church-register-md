const express = require('express');
const router  = express.Router();
const {
  login, getMe, changePassword,
  register, getAdmins, updateAdmin, deleteAdmin,
} = require('../controllers/auth.controller');
const { protect, restrictTo } = require('../middleware/auth.middleware');

// ─── Public ────────────────────────────────────────────────────────
router.post('/login', login);

// ─── Protégées (token requis) ──────────────────────────────────────
router.get('/me',              protect, getMe);
router.patch('/change-password', protect, changePassword);

// ─── Gestion admins (super_admin uniquement) ───────────────────────
router.get('/admins',          protect, restrictTo('super_admin'), getAdmins);
router.post('/register',       protect, restrictTo('super_admin'), register);
router.patch('/admins/:id',    protect, restrictTo('super_admin'), updateAdmin);
router.delete('/admins/:id',   protect, restrictTo('super_admin'), deleteAdmin);

module.exports = router;