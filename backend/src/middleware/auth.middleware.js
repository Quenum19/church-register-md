const jwt  = require('jsonwebtoken');
const User = require('../models/User.model');

const protect = async (req, res, next) => {
  try {
    const authHeader = req.headers.authorization;
    if (!authHeader?.startsWith('Bearer ')) {
      return res.status(401).json({ status: 'fail', message: 'Non authentifié. Connectez-vous.' });
    }

    const token = authHeader.split(' ')[1];

    let decoded;
    try {
      decoded = jwt.verify(token, process.env.JWT_SECRET);
    } catch (err) {
      const msg = err.name === 'TokenExpiredError'
        ? 'Session expirée. Reconnectez-vous.'
        : 'Token invalide.';
      return res.status(401).json({ status: 'fail', message: msg });
    }

    const user = await User.findById(decoded.id).select('-password');
    if (!user || !user.isActive) {
      return res.status(401).json({ status: 'fail', message: 'Utilisateur introuvable ou désactivé.' });
    }

    req.user = user;
    next();
  } catch (err) {
    console.error('[protect]', err);
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

const restrictTo = (...roles) => (req, res, next) => {
  if (!roles.includes(req.user?.role)) {
    return res.status(403).json({
      status:  'fail',
      message: `Accès réservé aux : ${roles.join(', ')}.`,
    });
  }
  next();
};

module.exports = { protect, restrictTo };