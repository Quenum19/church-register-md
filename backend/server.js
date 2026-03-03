const express   = require('express');
const cors      = require('cors');
const helmet    = require('helmet');
const morgan    = require('morgan');
const rateLimit = require('express-rate-limit');
require('dotenv').config();

const connectDB = require('./src/config/db');

const app = express();

// ── 1. MongoDB ────────────────────────────────────────────────────
connectDB();

// ── 2. Helmet (headers sécurité HTTP) ─────────────────────────────
app.use(helmet());

// ── 3. CORS ───────────────────────────────────────────────────────
const ALLOWED = [
  process.env.FRONTEND_URL,
  'https://church-register-md.vercel.app',
  'http://localhost:3000',
  'http://127.0.0.1:3000',
].filter(Boolean);

app.use(cors({
  origin: (origin, cb) => {
    if (!origin) return cb(null, true);

    if (ALLOWED.includes(origin)) {
      return cb(null, true);
    }

    console.log("❌ Origin bloquée:", origin);
    return cb(new Error('Origine non autorisée'));
  },
  credentials: true,
  methods: ['GET','POST','PUT','PATCH','DELETE','OPTIONS'],
  allowedHeaders: ['Content-Type','Authorization'],
}));

app.options('*', cors());

// ── 4. Parsing ────────────────────────────────────────────────────
app.use(express.json({ limit: '10kb' }));
app.use(express.urlencoded({ extended: true }));

// ── 5. Logs (off en production) ───────────────────────────────────
if (process.env.NODE_ENV !== 'production') app.use(morgan('dev'));

// ── 6. Sanitisation NoSQL basique (sans package externe) ──────────
app.use((req, _res, next) => {
  const clean = (obj) => {
    if (!obj || typeof obj !== 'object') return;
    Object.keys(obj).forEach(k => {
      if (k.startsWith('$') || k.includes('.')) delete obj[k];
      else if (typeof obj[k] === 'object') clean(obj[k]);
    });
  };
  clean(req.body);
  clean(req.params);
  next();
});

// ── 7. Rate limiting ──────────────────────────────────────────────
// Global : 200 req / 15 min
app.use(rateLimit({
  windowMs: 15 * 60 * 1000, max: 200,
  standardHeaders: true, legacyHeaders: false,
  message: { status: 'error', message: 'Trop de requêtes. Réessayez dans 15 minutes.' },
}));

// Login strict : 10 tentatives / 15 min
app.use('/api/auth/login', rateLimit({
  windowMs: 15 * 60 * 1000, max: 10,
  message: { status: 'error', message: 'Trop de tentatives. Réessayez dans 15 minutes.' },
}));

// ── 8. Routes ─────────────────────────────────────────────────────
app.use('/api/auth',     require('./src/routes/auth.routes'));
app.use('/api/visitors', require('./src/routes/visitor.routes'));
app.use('/api/reports',  require('./src/routes/report.routes'));
app.use('/api/qrcode',   require('./src/routes/qrcode.routes'));

// ── 9. Health check (Render l'utilise pour savoir si le serveur vit)
app.get('/api/health', (_req, res) =>
  res.json({ status: 'OK', time: new Date().toISOString() })
);

// ── 10. Cron ──────────────────────────────────────────────────────
require('./src/cron/cronJobs');

// ── 11. Erreurs globales ──────────────────────────────────────────
app.use((err, _req, res, _next) => {
  console.error('[Error]', err.message);
  res.status(err.status || 500).json({
    status: 'error',
    message: process.env.NODE_ENV === 'production' ? 'Erreur serveur.' : err.message,
  });
});

const PORT = process.env.PORT || 5000;
app.listen(PORT, () =>
  console.log(`✅ Server running on port ${PORT} [${process.env.NODE_ENV || 'development'}]`)
);