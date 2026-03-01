const Visitor  = require('../models/Visitor.model');
const { getCurrentFamily } = require('../utils/familyService');

/* ─── Traduction des erreurs Mongoose ───────────────────────────── */
const formatMongooseError = (err) => {
  if (err.name === 'ValidationError') {
    const messages = Object.values(err.errors).map(e => {
      if (e.kind === 'enum')     return `La valeur "${e.value}" n'est pas valide pour le champ "${e.path}".`;
      if (e.kind === 'required') return `Le champ "${e.path}" est obligatoire.`;
      return e.message;
    });
    return messages.join(' ');
  }
  if (err.code === 11000) return 'Ce numéro de téléphone est déjà enregistré.';
  return 'Une erreur interne est survenue.';
};

/* ─── POST /visitors/identify ───────────────────────────────────── */
exports.identify = async (req, res) => {
  try {
    const { phone } = req.body;
    if (!phone?.trim()) {
      return res.status(400).json({ status: 'error', message: 'Le numéro de téléphone est requis.' });
    }

    const visitor = await Visitor.findOne({ phone: phone.trim() });

    if (!visitor) {
      return res.status(200).json({
        status: 'success',
        data: { nextForm: 1, isNew: true, visitor: null, familleAccueil: getCurrentFamily() },
      });
    }

    if (visitor.visitCount >= 3) {
      return res.status(200).json({
        status: 'success',
        data: { nextForm: null, isNew: false, visitor, message: 'Parcours complet. Parlez à un responsable !' },
      });
    }

    return res.status(200).json({
      status: 'success',
      data: {
        nextForm:       visitor.visitCount + 1,
        isNew:          false,
        visitor,
        familleAccueil: getCurrentFamily(),
      },
    });
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

/* ─── POST /visitors/visit1 ─────────────────────────────────────── */
exports.visit1 = async (req, res) => {
  try {
    const {
      phone, fullName, whatsapp, contactPhone,
      city, neighborhood,
      inviteSource, invitedBy, congregation,
      wantsWhatsAppGroup, notes,
    } = req.body;

    if (!phone?.trim()) return res.status(400).json({ status: 'error', message: 'Numéro requis.' });

    let visitor = await Visitor.findOne({ phone: phone.trim() });
    if (visitor?.visitCount >= 1) {
      return res.status(400).json({ status: 'error', message: 'La première visite est déjà enregistrée.' });
    }

    if (!visitor) visitor = new Visitor({ phone: phone.trim() });

    visitor.visit1 = {
      fullName:           fullName?.trim(),
      whatsapp:           whatsapp?.trim()      || undefined,
      contactPhone:       contactPhone?.trim()  || undefined,
      city:               city?.trim(),
      neighborhood:       neighborhood?.trim(),
      inviteSource:       inviteSource          || undefined,
      invitedBy:          inviteSource === 'invite_membre' ? invitedBy?.trim() : undefined,
      congregation:       inviteSource === 'invite_membre' ? congregation      : undefined,
      wantsWhatsAppGroup: !!wantsWhatsAppGroup,
      familleAccueil:     getCurrentFamily(),
      notes:              notes?.trim()         || undefined,
    };
    visitor.visitCount = 1;

    await visitor.save();
    res.status(201).json({ status: 'success', message: 'Première visite enregistrée.', data: { visitor } });
  } catch (err) {
    const message = formatMongooseError(err);
    res.status(err.name === 'ValidationError' ? 400 : 500).json({ status: 'error', message });
  }
};

/* ─── POST /visitors/visit2 ─────────────────────────────────────── */
exports.visit2 = async (req, res) => {
  try {
    const { phone, fullName, returnReasons, returnReasonsOther } = req.body;

    if (!phone?.trim()) return res.status(400).json({ status: 'error', message: 'Numéro requis.' });

    const visitor = await Visitor.findOne({ phone: phone.trim() });

    if (!visitor || visitor.visitCount < 1) {
      return res.status(400).json({
        status: 'error',
        message: 'La première visite doit être enregistrée avant la deuxième.',
      });
    }
    if (visitor.visitCount >= 2) {
      return res.status(400).json({ status: 'error', message: 'La deuxième visite est déjà enregistrée.' });
    }

    const trimmedName = fullName?.trim();

    // ── Synchronisation du nom : si l'accueillant a corrigé, on met à jour visit1.fullName
    if (trimmedName && visitor.visit1 && trimmedName !== visitor.visit1.fullName) {
      visitor.visit1.fullName = trimmedName;
    }

    visitor.visit2 = {
      fullName:           trimmedName,   // même nom synchronisé
      returnReasons:      Array.isArray(returnReasons) ? returnReasons : [],
      returnReasonsOther: returnReasonsOther?.trim() || undefined,
      familleAccueil:     getCurrentFamily(),
    };
    visitor.visitCount = 2;

    // markModified pour que Mongoose détecte le changement dans le sous-document visit1
    visitor.markModified('visit1');

    await visitor.save();
    res.status(201).json({ status: 'success', message: 'Deuxième visite enregistrée.', data: { visitor } });
  } catch (err) {
    const message = formatMongooseError(err);
    res.status(err.name === 'ValidationError' ? 400 : 500).json({ status: 'error', message });
  }
};

/* ─── POST /visitors/visit3 ─────────────────────────────────────── */
exports.visit3 = async (req, res) => {
  try {
    const { phone, fullName, visitReason } = req.body;

    if (!phone?.trim()) return res.status(400).json({ status: 'error', message: 'Numéro requis.' });

    const visitor = await Visitor.findOne({ phone: phone.trim() });

    if (!visitor || visitor.visitCount < 2) {
      return res.status(400).json({
        status: 'error',
        message: 'Les deux premières visites doivent être enregistrées avant la troisième.',
      });
    }
    if (visitor.visitCount >= 3) {
      return res.status(400).json({ status: 'error', message: 'La troisième visite est déjà enregistrée.' });
    }

    const trimmedName = fullName?.trim();

    // ── Synchronisation du nom : correction propagée à visit1
    if (trimmedName && visitor.visit1 && trimmedName !== visitor.visit1.fullName) {
      visitor.visit1.fullName = trimmedName;
      visitor.markModified('visit1');
    }
    // Et visit2 aussi si elle existe
    if (trimmedName && visitor.visit2 && trimmedName !== visitor.visit2.fullName) {
      visitor.visit2.fullName = trimmedName;
      visitor.markModified('visit2');
    }

    visitor.visit3 = {
      fullName:      trimmedName,
      visitReason:   visitReason || undefined,
      familleAccueil: getCurrentFamily(),
    };
    visitor.visitCount = 3;

    await visitor.save();
    res.status(201).json({ status: 'success', message: 'Troisième visite enregistrée.', data: { visitor } });
  } catch (err) {
    const message = formatMongooseError(err);
    res.status(err.name === 'ValidationError' ? 400 : 500).json({ status: 'error', message });
  }
};

/* ─── GET /visitors/stats ───────────────────────────────────────── */
exports.getStats = async (req, res) => {
  try {
    const startOfMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);

    const [total, thisMonth, byStatus, byFamily] = await Promise.all([
      Visitor.countDocuments(),
      Visitor.countDocuments({ createdAt: { $gte: startOfMonth } }),
      Visitor.aggregate([{ $group: { _id: '$status', count: { $sum: 1 } } }]),
      Visitor.aggregate([{
        $facet: {
          v1: [{ $match: { 'visit1.familleAccueil': { $exists: true } } },
               { $group: { _id: '$visit1.familleAccueil', count: { $sum: 1 } } }],
          v2: [{ $match: { 'visit2.familleAccueil': { $exists: true } } },
               { $group: { _id: '$visit2.familleAccueil', count: { $sum: 1 } } }],
          v3: [{ $match: { 'visit3.familleAccueil': { $exists: true } } },
               { $group: { _id: '$visit3.familleAccueil', count: { $sum: 1 } } }],
        },
      }]),
    ]);

    res.json({
      status: 'success',
      data: { total, thisMonth, byStatus, byFamily: byFamily[0], familleActuelle: getCurrentFamily() },
    });
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

/* ─── GET /visitors ─────────────────────────────────────────────── */
exports.getAll = async (req, res) => {
  try {
    const { page = 1, limit = 20, status, search, famille, period } = req.query;
    const query = {};

    if (status) query.status = status;

    if (famille) {
      query.$or = [
        { 'visit1.familleAccueil': famille },
        { 'visit2.familleAccueil': famille },
        { 'visit3.familleAccueil': famille },
      ];
    }

    if (search) {
      const re = new RegExp(search.trim(), 'i');
      query.$or = [
        { phone: re },
        { 'visit1.fullName': re },
        { 'visit2.fullName': re },
      ];
    }

    // Filtre période sur createdAt
    if (period === 'month') {
      const now = new Date();
      query.createdAt = {
        $gte: new Date(now.getFullYear(), now.getMonth(), 1),
        $lt:  new Date(now.getFullYear(), now.getMonth() + 1, 1),
      };
    } else if (period === 'year') {
      const now = new Date();
      query.createdAt = {
        $gte: new Date(now.getFullYear(), 0, 1),
        $lt:  new Date(now.getFullYear() + 1, 0, 1),
      };
    }

    const [visitors, total] = await Promise.all([
      Visitor.find(query)
        .sort({ createdAt: -1 })
        .skip((page - 1) * limit)
        .limit(Number(limit)),
      Visitor.countDocuments(query),
    ]);

    res.json({
      status: 'success',
      data: { visitors, total, page: Number(page), pages: Math.ceil(total / limit) },
    });
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

/* ─── GET /visitors/:id ─────────────────────────────────────────── */
exports.getOne = async (req, res) => {
  try {
    const visitor = await Visitor.findById(req.params.id)
      .populate('convertedToMemberBy', 'firstName lastName');
    if (!visitor) return res.status(404).json({ status: 'error', message: 'Visiteur introuvable.' });
    res.json({ status: 'success', data: { visitor } });
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

/* ─── PATCH /visitors/:id ───────────────────────────────────────── */
exports.update = async (req, res) => {
  try {
    const ALLOWED = ['notes', 'visit1', 'visit2', 'visit3'];
    const updates = {};
    ALLOWED.forEach(k => { if (req.body[k] !== undefined) updates[k] = req.body[k]; });

    const visitor = await Visitor.findByIdAndUpdate(req.params.id, updates, { new: true, runValidators: true });
    if (!visitor) return res.status(404).json({ status: 'error', message: 'Visiteur introuvable.' });
    res.json({ status: 'success', data: { visitor } });
  } catch (err) {
    const message = formatMongooseError(err);
    res.status(400).json({ status: 'error', message });
  }
};

/* ─── PATCH /visitors/:id/convert ───────────────────────────────── */
exports.convertToMember = async (req, res) => {
  try {
    const visitor = await Visitor.findById(req.params.id);
    if (!visitor) return res.status(404).json({ status: 'error', message: 'Visiteur introuvable.' });
    if (visitor.status === 'membre') {
      return res.status(400).json({ status: 'error', message: 'Ce visiteur est déjà membre.' });
    }
    visitor.status              = 'membre';
    visitor.convertedToMemberBy = req.user._id;
    visitor.convertedToMemberAt = new Date();
    await visitor.save();
    res.json({ status: 'success', message: 'Visiteur converti en membre.', data: { visitor } });
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};

/* ─── DELETE /visitors/:id ──────────────────────────────────────── */
exports.delete = async (req, res) => {
  try {
    const visitor = await Visitor.findByIdAndDelete(req.params.id);
    if (!visitor) return res.status(404).json({ status: 'error', message: 'Visiteur introuvable.' });
    res.json({ status: 'success', message: 'Fiche supprimée.' });
  } catch (err) {
    res.status(500).json({ status: 'error', message: 'Erreur serveur.' });
  }
};