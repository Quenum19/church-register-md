require('dotenv').config();
const mongoose = require('mongoose');

// ── Adapter le chemin selon votre structure ──
const User = require('./src/models/User.model');

const ADMIN = {
  firstName: 'Super',
  lastName:  'Admin',
  email:     'admin@destinycare.com',   // ← changer si besoin
  password:  'DestinyCare@2026!',               // ← changer IMPÉRATIVEMENT après la 1ère connexion
  role:      'super_admin',
};

(async () => {
  try {
    await mongoose.connect(process.env.MONGODB_URI || process.env.DATABASE_URL);
    console.log('✅ Connecté à MongoDB');

    const existing = await User.findOne({ email: ADMIN.email });
    if (existing) {
      console.log('⚠️  Un compte avec cet email existe déjà :', ADMIN.email);
      process.exit(0);
    }

    await User.create(ADMIN);

    console.log('✅ Super Admin créé avec succès !');
    console.log('   Email    :', ADMIN.email);
    console.log('   Password :', ADMIN.password);
    console.log('   ⚠️  Changez ce mot de passe dès la première connexion !');
  } catch (err) {
    console.error('❌ Erreur :', err.message);
  } finally {
    await mongoose.disconnect();
    process.exit(0);
  }
})();