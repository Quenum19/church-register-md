const mongoose = require('mongoose');

const familyConfigSchema = new mongoose.Schema({
  family: {
    type: String,
    required: true,
    unique: true,
    enum: ['Force', 'Honneur', 'Gloire', 'Louange', 'Puissance', 'Richesse', 'Sagesse'],
  },

  // Jusqu'à 5 destinataires par famille
  recipients: [{
    name:  { type: String, required: true },
    email: { type: String, required: true, lowercase: true, trim: true },
    role:  { type: String, enum: ['responsable', 'pasteur', 'autre'], default: 'responsable' },
  }],

  // Activer/désactiver l'envoi automatique pour cette famille
  autoReportEnabled: { type: Boolean, default: true },

  updatedBy: { type: mongoose.Schema.Types.ObjectId, ref: 'User' },

}, { timestamps: true });

module.exports = mongoose.model('FamilyConfig', familyConfigSchema);