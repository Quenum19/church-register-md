require('dotenv').config({ path: '../../.env' });
const mongoose = require('mongoose');
const User = require('../models/User.model');

const seed = async () => {
  try {
    await mongoose.connect(process.env.MONGODB_URI);
    console.log('✅ Connecté à MongoDB');

    // Vérifie si un super admin existe déjà
    const existing = await User.findOne({ role: 'super_admin' });
    if (existing) {
      console.log('⚠️  Un super admin existe déjà :', existing.email);
      process.exit(0);
    }

    // Création du super admin
    const superAdmin = await User.create({
      firstName: 'Admin',
      lastName: 'Principal',
      email: 'admin@destinycare.com',      // ← modifiez ici
      password: 'DestinyCare1!',              // ← modifiez ici
      role: 'super_admin',
      isActive: true,
    });

    console.log('✅ Super Admin créé avec succès !');
    console.log('   Email    :', superAdmin.email);
    console.log('   Rôle     :', superAdmin.role);
    console.log('   ⚠️  Changez le mot de passe à la première connexion !');
    process.exit(0);

  } catch (err) {
    console.error('❌ Erreur seed :', err.message);
    process.exit(1);
  }
};

seed();