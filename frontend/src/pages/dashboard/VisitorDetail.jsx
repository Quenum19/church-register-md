import { useEffect, useState } from 'react';
import { getVisitor, updateVisitor, convertToMember, deleteVisitor } from '../../api/admin';
import { useAuth } from '../../context/AuthContext';

const FAMILY_ICONS = { Force:'⚡', Honneur:'🏅', Gloire:'✨', Louange:'🎵', Puissance:'💪', Richesse:'🌿', Sagesse:'📖' };
const SOURCE_LABELS = {
  invite_membre:'👥 Invité par un membre', saint_esprit:'✨ Saint Esprit',
  reseaux_sociaux:'📱 Réseaux sociaux', affiche_tract:'📋 Affiche / Tract',
  bouche_a_oreille:'🗣️ Bouche à oreille', passage:'🚶 Passage devant l\'église', autre:'✦ Autre',
};
const REASON_LABELS = {
  enseignement:'📖 Enseignement de la Parole', chaleur_fraternelle:'🤝 Chaleur fraternelle',
  louange_adoration:'🎵 Louange & adoration', accueil:'💛 Accueil reçu', autres:'✦ Autre(s)',
};
const VISIT_REASON_LABELS = {
  nouveau_resident:'🏠 Nouveau résident', devenir_membre:'⭐ Désir de devenir membre',
  vacances:'✈️ De passage / Vacances', autres:'✦ Autre',
};
const STATUS_CONFIG = {
  prospect:         { label: 'Prospect',          color: 'bg-blue-50 text-blue-700 border-blue-200' },
  recurrent:        { label: 'Récurrent',          color: 'bg-orange-50 text-orange-700 border-orange-200' },
  membre_potentiel: { label: 'Membre potentiel',   color: 'bg-purple-50 text-church-purple border-purple-200' },
  membre:           { label: '✅ Membre confirmé', color: 'bg-green-50 text-green-700 border-green-200' },
};

const fmt = (d) => d ? new Date(d).toLocaleDateString('fr-FR', { weekday:'long', day:'numeric', month:'long', year:'numeric' }) : '—';
const fmtShort = (d) => d ? new Date(d).toLocaleDateString('fr-FR') : '—';

/* ── Modal confirmation pro ──────────────────────────────────────── */
const ConfirmModal = ({ type, name, onConfirm, onCancel }) => (
  <div className="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
    <div className="bg-white rounded-2xl p-7 max-w-sm w-full shadow-2xl animate-fade-up">
      <div className={`w-16 h-16 rounded-full mx-auto flex items-center justify-center text-3xl mb-4
                      ${type === 'convert' ? 'bg-green-50' : 'bg-red-50'}`}>
        {type === 'convert' ? '✅' : '🗑️'}
      </div>
      <h3 className="font-display text-lg font-bold text-gray-800 text-center mb-2">
        {type === 'convert' ? 'Convertir en membre' : 'Supprimer la fiche'}
      </h3>
      <p className="text-sm text-gray-500 font-body text-center mb-1">
        {type === 'convert'
          ? <><strong className="text-gray-700">{name}</strong> a complété ses 3 visites. Confirmer la conversion en membre officiel ?</>
          : <>Supprimer définitivement la fiche de <strong className="text-gray-700">{name}</strong> ?</>}
      </p>
      {type === 'delete' && (
        <p className="text-xs text-red-400 font-body text-center mt-1">⚠️ Cette action est irréversible.</p>
      )}
      <div className="flex gap-3 mt-5">
        <button onClick={onCancel}
          className="flex-1 px-4 py-2.5 rounded-xl border-2 border-gray-200 text-gray-600
                     font-body font-semibold text-sm hover:border-gray-300 transition-all">
          Annuler
        </button>
        <button onClick={onConfirm}
          className={`flex-1 px-4 py-2.5 rounded-xl text-white font-body font-semibold text-sm transition-all
            ${type === 'convert' ? 'bg-green-500 hover:bg-green-600' : 'bg-red-500 hover:bg-red-600'}`}>
          {type === 'convert' ? 'Oui, convertir ✅' : 'Oui, supprimer'}
        </button>
      </div>
    </div>
  </div>
);

/* ── Ligne info ──────────────────────────────────────────────────── */
const InfoRow = ({ icon, label, value }) => {
  if (!value) return null;
  return (
    <div className="flex items-start gap-3">
      <span className="text-base shrink-0 mt-0.5">{icon}</span>
      <div>
        <p className="text-xs text-gray-400 font-body uppercase tracking-wide">{label}</p>
        <p className="text-sm text-gray-700 font-body font-medium">{value}</p>
      </div>
    </div>
  );
};

/* ── Carte de visite ─────────────────────────────────────────────── */
const VisitCard = ({ number, visit, visitType }) => {
  if (!visit) return (
    <div className="flex items-center gap-4">
      <div className="flex flex-col items-center shrink-0">
        <div className="w-9 h-9 rounded-full border-2 border-dashed border-gray-200 flex items-center justify-center text-gray-300 text-sm font-bold">
          {number}
        </div>
        {number < 3 && <div className="w-px h-8 bg-gray-100 mt-1" />}
      </div>
      <div className="flex-1 mb-4">
        <p className="text-sm text-gray-300 font-body italic">Visite {number} non encore enregistrée</p>
      </div>
    </div>
  );

  const dotColors = ['bg-church-purple','bg-church-gold','bg-green-500'];
  const familyIcon = FAMILY_ICONS[visit.familleAccueil] || '🏠';

  return (
    <div className="flex items-start gap-4">
      <div className="flex flex-col items-center shrink-0">
        <div className={`w-9 h-9 rounded-full ${dotColors[number-1]} flex items-center justify-center text-white text-sm font-bold shadow-sm`}>
          {number}
        </div>
        {number < 3 && <div className="w-px h-full min-h-[40px] bg-gray-100 mt-1 mb-1" />}
      </div>
      <div className="flex-1 bg-gray-50 rounded-2xl p-5 mb-4 border border-gray-100">
        <div className="flex items-start justify-between mb-3">
          <div>
            <p className="font-display font-bold text-gray-800 text-sm">
              {number === 1 ? '1ère' : `${number}ème`} Visite
            </p>
            <p className="text-xs text-gray-400 font-body">{fmt(visit.date)}</p>
          </div>
          {visit.familleAccueil && (
            <span className="flex items-center gap-1.5 text-xs font-body font-bold px-3 py-1.5 rounded-full bg-white border border-gray-200 text-gray-700 shadow-sm">
              {familyIcon} Famille {visit.familleAccueil}
            </span>
          )}
        </div>
        <div className="flex flex-col gap-2.5">
          {visitType === 1 && <>
            <InfoRow icon="📍" label="Domicile"       value={[visit.neighborhood, visit.city].filter(Boolean).join(', ')} />
            <InfoRow icon="📞" label="WhatsApp"        value={visit.whatsapp} />
            <InfoRow icon="📱" label="Autre contact"   value={visit.contactPhone} />
            <InfoRow icon="🔗" label="Source"          value={SOURCE_LABELS[visit.inviteSource]} />
            {visit.invitedBy && <InfoRow icon="👤" label="Invité par"
              value={`${visit.invitedBy}${visit.congregation ? ` · Congrégation ${visit.congregation}` : ''}`} />}
            {visit.wantsWhatsAppGroup && (
              <div className="flex items-center gap-2 text-xs text-green-700 bg-green-50 px-3 py-1.5 rounded-lg border border-green-100">
                <span>✓</span> Souhaite rejoindre le groupe WhatsApp
              </div>
            )}
          </>}
          {visitType === 2 && visit.returnReasons?.length > 0 && (
            <div>
              <p className="text-xs text-gray-400 font-body uppercase tracking-wide mb-1.5">Raisons du retour</p>
              <div className="flex flex-wrap gap-1.5">
                {visit.returnReasons.map(r => (
                  <span key={r} className="text-xs bg-amber-50 text-amber-700 border border-amber-200 px-2.5 py-1 rounded-full font-body">
                    {REASON_LABELS[r] || r}
                  </span>
                ))}
              </div>
              {visit.returnReasonsOther && <p className="text-xs text-gray-500 font-body mt-1.5 italic">"{visit.returnReasonsOther}"</p>}
            </div>
          )}
          {visitType === 3 && <InfoRow icon="🎯" label="Raison principale" value={VISIT_REASON_LABELS[visit.visitReason]} />}
          {visit.notes && <InfoRow icon="📝" label="Notes" value={visit.notes} />}
        </div>
      </div>
    </div>
  );
};

/* ── Composant principal ─────────────────────────────────────────── */
const VisitorDetail = ({ visitorId, onBack }) => {
  const { canEdit, canDelete } = useAuth();
  const [visitor, setVisitor] = useState(null);
  const [loading, setLoading] = useState(true);
  const [notes,   setNotes]   = useState('');
  const [editNote,setEditNote]= useState(false);
  const [saving,  setSaving]  = useState(false);
  const [modal,   setModal]   = useState(null); // 'convert' | 'delete'

  useEffect(() => {
    setLoading(true);
    getVisitor(visitorId)
      .then(({ data }) => { setVisitor(data.data.visitor); setNotes(data.data.visitor.notes || ''); })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [visitorId]);

  const handleSaveNotes = async () => {
    setSaving(true);
    try {
      const { data } = await updateVisitor(visitorId, { notes });
      setVisitor(data.data.visitor);
      setEditNote(false);
    } catch(e) {} finally { setSaving(false); }
  };

  const handleConvert = async () => {
    try {
      const { data } = await convertToMember(visitorId);
      setVisitor(data.data.visitor);
    } catch(e) {}
    setModal(null);
  };

  const handleDelete = async () => {
    await deleteVisitor(visitorId);
    onBack();
  };

  if (loading) return (
    <div className="flex items-center justify-center h-64 text-gray-400">
      <svg className="animate-spin w-6 h-6 mr-2 text-church-purple" fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
      <span className="text-sm font-body">Chargement…</span>
    </div>
  );

  if (!visitor) return (
    <div className="text-center py-16 text-gray-400">
      <p className="text-4xl mb-3">😕</p>
      <p className="text-sm font-body">Visiteur introuvable.</p>
      <button onClick={onBack} className="mt-4 text-sm text-church-purple font-body font-semibold hover:underline">← Retour</button>
    </div>
  );

  const name       = visitor.visit1?.fullName || visitor.visit2?.fullName || '—';
  const statusCfg  = STATUS_CONFIG[visitor.status] || STATUS_CONFIG.prospect;

  // ── Condition stricte : seulement si 3 visites ET pas encore membre ──
  const canConvert = canEdit && visitor.visitCount >= 3 && visitor.status !== 'membre';
  const notYet3    = canEdit && visitor.visitCount < 3 && visitor.status !== 'membre';

  return (
    <div className="max-w-3xl flex flex-col gap-6">
      <button onClick={onBack}
        className="flex items-center gap-2 text-sm text-gray-500 hover:text-church-purple font-body font-semibold transition-colors w-fit">
        ← Retour à la liste
      </button>

      {/* En-tête */}
      <div className="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm">
        <div className="flex items-start justify-between flex-wrap gap-4">
          <div className="flex items-center gap-4">
            <div className="w-14 h-14 rounded-full bg-church-purple/10 flex items-center justify-center text-church-purple font-display font-bold text-xl shrink-0">
              {name[0]}
            </div>
            <div>
              <h1 className="font-display text-xl font-bold text-gray-800">{name}</h1>
              <p className="text-gray-400 font-body text-sm">{visitor.phone}</p>
              <div className="flex items-center gap-2 mt-1.5 flex-wrap">
                <span className={`text-xs font-body font-bold px-2.5 py-1 rounded-full border ${statusCfg.color}`}>
                  {statusCfg.label}
                </span>
                <span className="text-xs text-gray-400 font-body">
                  {visitor.visitCount} / 3 visite{visitor.visitCount > 1 ? 's' : ''}
                </span>
                {/* Barre progression */}
                <div className="flex gap-1">
                  {[1,2,3].map(n => (
                    <div key={n} className={`w-6 h-1.5 rounded-full ${n <= visitor.visitCount ? 'bg-church-purple' : 'bg-gray-200'}`} />
                  ))}
                </div>
              </div>
            </div>
          </div>

          {/* Actions */}
          <div className="flex flex-col gap-2">
            {canConvert && (
              <button onClick={() => setModal('convert')}
                className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-green-500 text-white
                           font-body font-semibold text-sm hover:bg-green-600 transition-all shadow-sm">
                ✅ Convertir en membre
              </button>
            )}
            {notYet3 && (
              <div className="px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-700 font-body text-xs text-center">
                🕐 {3 - visitor.visitCount} visite{3-visitor.visitCount>1?'s':''} restante{3-visitor.visitCount>1?'s':''} de devenir membre
              </div>
            )}
            {visitor.status === 'membre' && visitor.convertedToMemberAt && (
              <p className="text-xs text-green-600 font-body text-right">
                ✅ Membre depuis le {fmtShort(visitor.convertedToMemberAt)}
              </p>
            )}
            {canDelete && (
              <button onClick={() => setModal('delete')}
                className="flex items-center gap-2 px-4 py-2.5 rounded-xl border-2 border-red-100 text-red-500
                           font-body font-semibold text-sm hover:bg-red-50 transition-all">
                🗑️ Supprimer la fiche
              </button>
            )}
          </div>
        </div>

        <div className="mt-4 pt-4 border-t border-gray-50 flex items-center gap-4 text-xs text-gray-400 font-body flex-wrap">
          <span>📅 Inscrit le {fmtShort(visitor.createdAt)}</span>
          {visitor.visit1?.city && <span>📍 {visitor.visit1.city}</span>}
          {visitor.visit1?.invitedBy && <span>👤 Invité par {visitor.visit1.invitedBy}</span>}
        </div>
      </div>

      {/* Timeline */}
      <div className="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm">
        <h2 className="font-display text-base font-bold text-gray-700 mb-6">Parcours de visite</h2>
        <VisitCard number={1} visit={visitor.visit1} visitType={1} />
        <VisitCard number={2} visit={visitor.visit2} visitType={2} />
        <VisitCard number={3} visit={visitor.visit3} visitType={3} />
      </div>

      {/* Notes */}
      <div className="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm">
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-display text-base font-bold text-gray-700">Notes internes</h2>
          {canEdit && !editNote && (
            <button onClick={() => setEditNote(true)}
              className="text-xs text-church-purple font-body font-semibold hover:text-church-gold-dk transition-colors">
              ✏️ Modifier
            </button>
          )}
        </div>
        {editNote ? (
          <div className="flex flex-col gap-3">
            <textarea value={notes} onChange={e => setNotes(e.target.value)} rows={4}
              placeholder="Notes internes…" className="church-input resize-none text-sm" />
            <div className="flex gap-2">
              <button onClick={handleSaveNotes} disabled={saving} className="btn-gold text-sm px-5 py-2">
                {saving ? 'Enregistrement…' : 'Enregistrer'}
              </button>
              <button onClick={() => { setNotes(visitor.notes || ''); setEditNote(false); }}
                className="px-4 py-2 rounded-xl border-2 border-gray-200 text-sm text-gray-600 font-body font-semibold hover:border-church-purple/40 transition-all">
                Annuler
              </button>
            </div>
          </div>
        ) : (
          <p className={`text-sm font-body ${visitor.notes ? 'text-gray-700' : 'text-gray-300 italic'}`}>
            {visitor.notes || 'Aucune note.'}
          </p>
        )}
      </div>

      {/* Modals */}
      {modal && (
        <ConfirmModal type={modal} name={name}
          onConfirm={modal === 'convert' ? handleConvert : handleDelete}
          onCancel={() => setModal(null)} />
      )}
    </div>
  );
};

export default VisitorDetail;