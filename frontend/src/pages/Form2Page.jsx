import { useState } from 'react';
import { submitVisit2 } from '../api/visitors';
import PageLayout from './PageLayout';
import Button from '../components/ui/Button';
import Alert  from '../components/ui/Alert';

const REASONS = [
  { value: 'enseignement',        label: '📖 L\'enseignement de la Parole' },
  { value: 'chaleur_fraternelle', label: '🤝 La chaleur fraternelle' },
  { value: 'louange_adoration',   label: '🎵 La louange & l\'adoration' },
  { value: 'accueil',             label: '💛 L\'accueil reçu' },
  { value: 'autres',              label: '✦ Autre(s) raison(s)', hasOtherInput: true },
];

const FAMILY_ICONS = {
  Force: '⚡', Honneur: '🏅', Gloire: '✨', Louange: '🎵',
  Puissance: '💪', Richesse: '🌿', Sagesse: '📖',
};

const translateError = (msg = '') => {
  if (msg.includes('première'))  return msg;
  if (msg.includes('required'))  return 'Certains champs obligatoires sont manquants.';
  return 'Une erreur est survenue. Veuillez réessayer.';
};

/* ─── Carte récap 1ère visite ─────────────────────────────────────── */
const RecapCard = ({ visitor }) => {
  const v1 = visitor?.visit1;
  if (!v1) return null;
  const fmt = (d) => d ? new Date(d).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) : '';

  return (
    <div className="rounded-xl border border-church-gold/30 bg-amber-50/70 p-4 flex flex-col gap-2">
      <p className="text-xs font-body font-bold text-gray-500 uppercase tracking-widest">Votre 1ère visite</p>
      {v1.date && (
        <div className="flex items-center gap-2 text-sm text-gray-700 font-body">
          <span>📅</span><span>{fmt(v1.date)}</span>
        </div>
      )}
      {v1.familleAccueil && (
        <div className="flex items-center gap-2 text-sm font-body">
          <span>{FAMILY_ICONS[v1.familleAccueil] || '🏠'}</span>
          <span className="text-gray-700">Accueilli(e) par la famille <strong className="text-church-purple">{v1.familleAccueil}</strong></span>
        </div>
      )}
      {v1.invitedBy && (
        <div className="flex items-center gap-2 text-sm font-body">
          <span>👤</span>
          <span className="text-gray-700">
            Invité(e) par <strong className="text-amber-700">{v1.invitedBy}</strong>
            {v1.congregation && (
              <span className="ml-1.5 text-xs text-gray-400 bg-white px-2 py-0.5 rounded-full border border-gray-200">
                {v1.congregation}
              </span>
            )}
          </span>
        </div>
      )}
    </div>
  );
};

/* ─── Bloc nom — pré-rempli avec option de correction ────────────── */
const NameField = ({ value, prefill, onChange }) => {
  const [editing, setEditing] = useState(!prefill);
  const isModified = prefill && value !== prefill;

  if (!editing) {
    return (
      <div>
        <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-1.5">
          Nom et Prénoms <span className="text-church-gold">*</span>
        </p>
        <div className="flex items-center gap-2 px-4 py-3 rounded-xl border-2 border-green-200 bg-green-50">
          <span className="text-green-600">✓</span>
          <span className="font-body font-semibold text-gray-800 flex-1 text-sm">{value}</span>
          <button type="button" onClick={() => setEditing(true)}
            className="text-xs text-church-purple font-body font-semibold
                       hover:text-church-gold-dk underline underline-offset-2 transition-colors shrink-0">
            Corriger
          </button>
        </div>
        <p className="text-xs text-gray-400 font-body mt-1">
          Récupéré depuis votre 1ère visite — cliquez "Corriger" si une erreur s'est glissée.
        </p>
      </div>
    );
  }

  return (
    <div>
      <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-1.5">
        Nom et Prénoms <span className="text-church-gold">*</span>
      </p>
      {isModified && (
        <p className="text-xs text-amber-600 font-body mb-1.5">
          ⚠️ Ce nom sera mis à jour sur votre fiche complète.
        </p>
      )}
      <div className="flex gap-2">
        <input type="text" value={value}
          onChange={e => onChange(e.target.value)}
          placeholder="Ex : Kouamé Jean-Pierre"
          className="church-input flex-1"
          autoFocus />
        {prefill && (
          <button type="button"
            onClick={() => { onChange(prefill); setEditing(false); }}
            className="px-3 py-2 rounded-xl border-2 border-gray-200 text-xs text-gray-600 font-body
                       font-semibold hover:border-church-purple/40 hover:text-church-purple transition-all shrink-0">
            Annuler
          </button>
        )}
      </div>
    </div>
  );
};

/* ─── Composant principal ─────────────────────────────────────────── */
const Form2Page = ({ phone, visitor, onSuccess, onBack }) => {
  const prefillName = visitor?.visit1?.fullName || '';

  const [form, setForm] = useState({
    fullName:           prefillName,
    returnReasons:      [],
    returnReasonsOther: '',
  });
  const [apiError, setApiError] = useState('');
  const [loading,  setLoading]  = useState(false);

  const toggleReason = (val) => {
    setForm(p => ({
      ...p,
      returnReasons: p.returnReasons.includes(val)
        ? p.returnReasons.filter(r => r !== val)
        : [...p.returnReasons, val],
    }));
    if (apiError) setApiError('');
  };

  const handleSubmit = async () => {
    setApiError('');
    if (!form.fullName.trim())           { setApiError('Le nom est obligatoire.'); return; }
    if (form.returnReasons.length === 0) { setApiError('Sélectionnez au moins une raison.'); return; }
    if (form.returnReasons.includes('autres') && !form.returnReasonsOther.trim()) {
      setApiError('Veuillez préciser vos autres raisons.'); return;
    }

    setLoading(true);
    try {
      const { data } = await submitVisit2({
        phone,
        fullName:           form.fullName.trim(),
        returnReasons:      form.returnReasons,
        returnReasonsOther: form.returnReasons.includes('autres')
                              ? form.returnReasonsOther.trim() : undefined,
      });
      onSuccess(data.data?.visitor);
    } catch (err) {
      setApiError(translateError(err.response?.data?.message || err.message || ''));
    } finally {
      setLoading(false);
    }
  };

  return (
    <PageLayout visitNumber={2} title="Deuxième Visite"
      subtitle="Vous êtes de retour ! Dites-nous ce qui vous a particulièrement touché."
      onBack={onBack}>
      <div className="flex flex-col gap-5">

        <RecapCard visitor={visitor} />

        {/* Nom unique — pré-rempli depuis F1 */}
        <NameField
          value={form.fullName}
          prefill={prefillName}
          onChange={v => { setForm(p => ({ ...p, fullName: v })); if (apiError) setApiError(''); }}
        />

        {/* Raisons du retour */}
        <div>
          <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-1">
            Pourquoi êtes-vous revenu(e) ? <span className="text-church-gold">*</span>
          </p>
          <p className="text-xs text-gray-500 font-body mb-3">Plusieurs réponses possibles</p>
          <div className="flex flex-col gap-2">
            {REASONS.map(({ value, label, hasOtherInput }) => {
              const checked = form.returnReasons.includes(value);
              return (
                <div key={value}>
                  <button type="button" onClick={() => toggleReason(value)}
                    className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 text-sm font-body
                                transition-all duration-200 text-left
                                ${checked
                                  ? 'border-church-gold bg-amber-50 text-amber-800 font-semibold'
                                  : 'border-gray-200 bg-white text-gray-700 hover:border-church-purple/30 hover:bg-purple-50/20'}`}>
                    <div className={`w-5 h-5 rounded border-2 flex items-center justify-center shrink-0 transition-all
                                     ${checked ? 'bg-church-gold border-church-gold' : 'border-gray-300'}`}>
                      {checked && <span className="text-white text-xs font-bold leading-none">✓</span>}
                    </div>
                    {label}
                  </button>
                  {hasOtherInput && checked && (
                    <div className="mt-2 ml-8 animate-fade-up">
                      <textarea placeholder="Précisez en quelques mots..."
                        value={form.returnReasonsOther}
                        onChange={e => setForm(p => ({ ...p, returnReasonsOther: e.target.value }))}
                        rows={2} className="church-input resize-none text-sm" />
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </div>

        {apiError && <Alert type="error" message={apiError} />}
        <Button onClick={handleSubmit} disabled={loading}>Valider ma visite ✦</Button>
      </div>
    </PageLayout>
  );
};

export default Form2Page;