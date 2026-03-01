const express = require('express');
const router  = express.Router();
const QRCode  = require('qrcode');
const { protect } = require('../middleware/auth.middleware');

// Stockage en mémoire + fichier JSON simple (pas besoin d'un modèle Mongoose pour ça)
const path = require('path');
const fs   = require('fs');

const CONFIG_FILE = path.join(__dirname, '../data/qrcode-config.json');

// S'assurer que le dossier data existe
const ensureDataDir = () => {
  const dir = path.dirname(CONFIG_FILE);
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
};

const VERSETS_PREDEFINIS = [
  { index: 0, ref: 'Jean 21:17', text: 'Si tu m\'aimes, pais mes brebis.' },
  { index: 1, ref: 'Jean 3:16',     text: 'Car Dieu a tant aimé le monde qu\'il a donné son Fils unique, afin que quiconque croit en lui ne périsse point, mais qu\'il ait la vie éternelle.' },
  { index: 2, ref: 'Psaumes 23:1',  text: 'L\'Éternel est mon berger : je ne manquerai de rien.' },
];

const DEFAULT_CONFIG = {
  versetIndex: 0,          // index dans VERSETS_PREDEFINIS (-1 si personnalisé)
  ref:  VERSETS_PREDEFINIS[0].ref,
  text: VERSETS_PREDEFINIS[0].text,
  updatedAt: new Date().toISOString(),
};

const readConfig = () => {
  try {
    if (fs.existsSync(CONFIG_FILE)) {
      return JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf8'));
    }
  } catch (e) {}
  return DEFAULT_CONFIG;
};

const writeConfig = (config) => {
  ensureDataDir();
  fs.writeFileSync(CONFIG_FILE, JSON.stringify(config, null, 2), 'utf8');
};

const getQRDataURL = async (url) =>
  QRCode.toDataURL(url, {
    errorCorrectionLevel: 'H',
    margin: 2,
    width:  400,
    color: { dark: '#3D0A5A', light: '#FFFFFF' },
  });

const getQRBuffer = async (url, width = 800) =>
  QRCode.toBuffer(url, {
    errorCorrectionLevel: 'H',
    margin: 2,
    width,
    color: { dark: '#3D0A5A', light: '#FFFFFF' },
  });

/* ─── PUBLIC — /api/qrcode/public ───────────────────────────────────
   Sans token — pour les accueillantes
   URL toujours courte : /qrcode
─────────────────────────────────────────────────────────────────── */
router.get('/public', async (req, res) => {
  try {
    const url       = process.env.FRONTEND_URL || 'http://localhost:3000';
    const config    = readConfig();
    const qrDataURL = await getQRDataURL(url);

    res.json({
      status: 'success',
      data: {
        qrDataURL,
        url,
        verset: { ref: config.ref, text: config.text },
        versetIndex: config.versetIndex,
        updatedAt: config.updatedAt,
      },
    });
  } catch (err) {
    res.status(500).json({ status: 'error', message: err.message });
  }
});

/* ─── GET /api/qrcode/config — lire la config actuelle ─────────── */
router.get('/config', protect, async (req, res) => {
  try {
    const config = readConfig();
    res.json({ status: 'success', data: { config, versets: VERSETS_PREDEFINIS } });
  } catch (err) {
    res.status(500).json({ status: 'error', message: err.message });
  }
});

/* ─── PUT /api/qrcode/config — sauvegarder le verset actif ─────── */
router.put('/config', protect, async (req, res) => {
  try {
    const { versetIndex, ref, text } = req.body;

    let config;
    if (versetIndex >= 0 && versetIndex < VERSETS_PREDEFINIS.length) {
      // Verset prédéfini
      const v = VERSETS_PREDEFINIS[versetIndex];
      config = { versetIndex, ref: v.ref, text: v.text, updatedAt: new Date().toISOString() };
    } else {
      // Verset personnalisé
      if (!ref || !text) {
        return res.status(400).json({ status: 'error', message: 'Référence et texte requis.' });
      }
      config = { versetIndex: -1, ref, text, updatedAt: new Date().toISOString() };
    }

    writeConfig(config);
    res.json({ status: 'success', message: 'Configuration sauvegardée.', data: { config } });
  } catch (err) {
    res.status(500).json({ status: 'error', message: err.message });
  }
});

/* ─── PROTÉGÉ — dashboard admin ─────────────────────────────────── */
router.get('/', protect, async (req, res) => {
  try {
    const url       = process.env.FRONTEND_URL || 'http://localhost:3000';
    const config    = readConfig();
    const qrDataURL = await getQRDataURL(url);
    res.json({
      status: 'success',
      data: { qrDataURL, url, verset: { ref: config.ref, text: config.text }, generatedAt: new Date().toISOString() },
    });
  } catch (err) {
    res.status(500).json({ status: 'error', message: err.message });
  }
});

/* ─── PNG haute résolution ───────────────────────────────────────── */
router.get('/png', protect, async (req, res) => {
  try {
    const url    = process.env.FRONTEND_URL || 'http://localhost:3000';
    const buffer = await getQRBuffer(url, 800);
    res.set('Content-Type', 'image/png');
    res.set('Content-Disposition', 'attachment; filename="qrcode-maison-destinee.png"');
    res.send(buffer);
  } catch (err) {
    res.status(500).json({ status: 'error', message: err.message });
  }
});

module.exports = router;