const User = require('../models/User.model');
const bcrypt = require('bcryptjs');
const { createSendToken } = require('../utils/jwt');

// ─── LOGIN ──────────────────────────────────────────────────────────
const login = async (req, res) => {
  try {
    const { email, password } = req.body;
    if (!email || !password)
      return res.status(400).json({ status: 'fail', message: 'Email et mot de passe requis.' });

    const user = await User.findOne({ email: email.toLowerCase() }).select('+password');
    if (!user || !(await user.comparePassword(password)))
      return res.status(401).json({ status: 'fail', message: 'Email ou mot de passe incorrect.' });

    if (!user.isActive)
      return res.status(401).json({ status: 'fail', message: 'Compte désactivé. Contactez un super admin.' });

    await User.findByIdAndUpdate(user._id, { lastLogin: Date.now() });
    createSendToken(user, 200, res);
  } catch (err) {
    console.error('[login]', err);
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

// ─── MOI ────────────────────────────────────────────────────────────
const getMe = async (req, res) => {
  try {
    const user = await User.findById(req.user._id);
    res.json({ status: 'success', data: { user } });
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

// ─── CHANGER MOT DE PASSE ───────────────────────────────────────────
const changePassword = async (req, res) => {
  try {
    const { currentPassword, newPassword } = req.body;
    const user = await User.findById(req.user._id).select('+password');
    if (!(await user.comparePassword(currentPassword)))
      return res.status(401).json({ status: 'fail', message: 'Mot de passe actuel incorrect.' });
    if (newPassword.length < 8)
      return res.status(400).json({ status: 'fail', message: 'Minimum 8 caractères.' });
    user.password = newPassword;
    await user.save();
    createSendToken(user, 200, res);
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

// ─── CRÉER UN ADMIN ─────────────────────────────────────────────────
const register = async (req, res) => {
  try {
    const { firstName, lastName, email, password, role } = req.body;
    if (!firstName || !lastName || !email || !password)
      return res.status(400).json({ status: 'fail', message: 'Tous les champs sont requis.' });

    const existing = await User.findOne({ email: email.toLowerCase() });
    if (existing)
      return res.status(400).json({ status: 'fail', message: 'Un compte avec cet email existe déjà.' });

    const allowedRoles = ['super_admin', 'moderateur', 'lecteur'];
    const hashed = await bcrypt.hash(password, 12);
    const user   = await User.create({
      firstName, lastName,
      email:    email.toLowerCase(),
      password: hashed,
      role:     allowedRoles.includes(role) ? role : 'lecteur',
    });
    user.password = undefined;
    res.status(201).json({ status: 'success', message: `Compte créé pour ${firstName} ${lastName}.`, data: { user } });
  } catch (err) {
    console.error('[register]', err);
    res.status(500).json({ status: 'error', message: `Erreur : ${err.message}` });
  }
};

// ─── LISTE ADMINS ───────────────────────────────────────────────────
const getAdmins = async (req, res) => {
  try {
    const users = await User.find().sort({ createdAt: -1 }).select('-password');
    res.json({ status: 'success', data: { users } });
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

// ─── MODIFIER ADMIN ─────────────────────────────────────────────────
const updateAdmin = async (req, res) => {
  try {
    const { firstName, lastName, email, role, password, isActive } = req.body;
    const { id } = req.params;
    if (String(req.user._id) === String(id))
      return res.status(400).json({ status: 'fail', message: 'Utilisez "Paramètres" pour modifier votre propre compte.' });

    const update = {};
    if (firstName)              update.firstName = firstName;
    if (lastName)               update.lastName  = lastName;
    if (email)                  update.email     = email.toLowerCase();
    if (role)                   update.role      = role;
    if (isActive !== undefined) update.isActive  = isActive;
    if (password) {
      if (password.length < 8)
        return res.status(400).json({ status: 'fail', message: 'Minimum 8 caractères.' });
      update.password = await bcrypt.hash(password, 12);
    }

    const user = await User.findByIdAndUpdate(id, update, { new: true }).select('-password');
    if (!user) return res.status(404).json({ status: 'fail', message: 'Utilisateur introuvable.' });
    res.json({ status: 'success', message: 'Compte mis à jour.', data: { user } });
  } catch (err) {
    res.status(500).json({ status: 'error', message: `Erreur : ${err.message}` });
  }
};

// ─── SUPPRIMER ADMIN ────────────────────────────────────────────────
const deleteAdmin = async (req, res) => {
  try {
    const { id } = req.params;
    if (String(req.user._id) === String(id))
      return res.status(400).json({ status: 'fail', message: 'Vous ne pouvez pas supprimer votre propre compte.' });
    const user = await User.findByIdAndDelete(id);
    if (!user) return res.status(404).json({ status: 'fail', message: 'Utilisateur introuvable.' });
    res.json({ status: 'success', message: `Compte de ${user.firstName} ${user.lastName} supprimé.` });
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

module.exports = { login, getMe, changePassword, register, getAdmins, updateAdmin, deleteAdmin };