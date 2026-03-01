const mongoose = require('mongoose');

const reportSchema = new mongoose.Schema({
  family:    { type: String, required: true }, // Force, Honneur, etc.
  month:     { type: Number, required: true }, // 1-12
  year:      { type: Number, required: true }, // 2026
  period:    { type: String },                 // "Février 2026"

  stats: {
    totalVisits:    { type: Number, default: 0 }, // total visites ce mois
    newVisitors:    { type: Number, default: 0 }, // visiteurs F1
    returning:      { type: Number, default: 0 }, // F2 + F3
    converted:      { type: Number, default: 0 }, // devenus membres
    byVisit: {
      v1: { type: Number, default: 0 },
      v2: { type: Number, default: 0 },
      v3: { type: Number, default: 0 },
    },
  },

  // Visiteurs reçus ce mois par cette famille
  visitors: [{
    name:       String,
    phone:      String,
    visitNumber: Number,
    date:       Date,
    status:     String,
  }],

  emailSentAt:  { type: Date },
  emailSentTo:  [{ type: String }],
  generatedAt:  { type: Date, default: Date.now },
  generatedBy:  { type: mongoose.Schema.Types.ObjectId, ref: 'User' }, // null si cron

}, { timestamps: true });

// Index unicité : un rapport par famille par mois
reportSchema.index({ family: 1, month: 1, year: 1 }, { unique: true });
reportSchema.index({ year: 1, month: 1 });

module.exports = mongoose.model('Report', reportSchema);