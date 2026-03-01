const mongoose = require('mongoose');

const FAMILIES = ['Force', 'Honneur', 'Gloire', 'Louange', 'Puissance', 'Richesse', 'Sagesse'];

/* ─── Schémas de visite ─────────────────────────────────────────── */

const visit1Schema = new mongoose.Schema({
  date:              { type: Date, default: Date.now },
  fullName:          { type: String, required: true, trim: true },
  whatsapp:          { type: String, trim: true },
  contactPhone:      { type: String, trim: true },
  city:              { type: String, required: true, trim: true },
  neighborhood:      { type: String, required: true, trim: true },
  inviteSource: {
    type: String,
    enum: ['invite_membre', 'saint_esprit', 'reseaux_sociaux', 'affiche_tract',
           'bouche_a_oreille', 'passage', 'autre'],
  },
  invitedBy:         { type: String, trim: true },          // nom du membre invitant
  congregation:      { type: String, enum: [...FAMILIES, ''], default: '' }, // congrégation du membre
  wantsWhatsAppGroup:{ type: Boolean, default: false },
  familleAccueil:    { type: String, enum: FAMILIES },      // famille de service ce mois-là
  notes:             { type: String, trim: true },
}, { _id: false });

const visit2Schema = new mongoose.Schema({
  date:                { type: Date, default: Date.now },
  fullName:            { type: String, required: true, trim: true },
  returnReasons: [{
    type: String,
    enum: ['enseignement', 'chaleur_fraternelle', 'louange_adoration', 'accueil', 'autres'],
  }],
  returnReasonsOther:  { type: String, trim: true },
  familleAccueil:      { type: String, enum: FAMILIES },    // famille de service ce mois-là
}, { _id: false });

const visit3Schema = new mongoose.Schema({
  date:          { type: Date, default: Date.now },
  fullName:      { type: String, required: true, trim: true },
  visitReason: {
    type: String,
    enum: ['nouveau_resident', 'devenir_membre', 'vacances', 'autres'],
  },
  familleAccueil:{ type: String, enum: FAMILIES },           // famille de service ce mois-là
}, { _id: false });

/* ─── Schéma principal Visitor ──────────────────────────────────── */

const visitorSchema = new mongoose.Schema({
  phone:       { type: String, required: true, unique: true, trim: true },
  visitCount:  { type: Number, default: 0, min: 0, max: 3 },
  status: {
    type: String,
    enum: ['prospect', 'recurrent', 'membre_potentiel', 'membre'],
    default: 'prospect',
  },

  visit1: { type: visit1Schema, default: null },
  visit2: { type: visit2Schema, default: null },
  visit3: { type: visit3Schema, default: null },

  convertedToMemberBy: { type: mongoose.Schema.Types.ObjectId, ref: 'User' },
  convertedToMemberAt: { type: Date },
  notes:               { type: String, trim: true },

}, { timestamps: true });

/* ─── Hook : statut automatique ─────────────────────────────────── */

visitorSchema.pre('save', async function () {
  if (this.status !== 'membre') {
    if (this.visitCount === 1) this.status = 'prospect';
    if (this.visitCount === 2) this.status = 'recurrent';
    if (this.visitCount === 3) this.status = 'membre_potentiel';
  }
});

/* ─── Index utiles pour le dashboard ────────────────────────────── */

visitorSchema.index({ phone: 1 });
visitorSchema.index({ status: 1 });
visitorSchema.index({ 'visit1.familleAccueil': 1 });
visitorSchema.index({ 'visit2.familleAccueil': 1 });
visitorSchema.index({ 'visit3.familleAccueil': 1 });
visitorSchema.index({ createdAt: -1 });

module.exports = mongoose.model('Visitor', visitorSchema);