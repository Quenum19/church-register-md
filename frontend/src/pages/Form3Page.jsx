import { useState } from 'react';
import { submitVisit3 } from '../api/visitors';
import PageLayout from './PageLayout';
import Button from '../components/ui/Button';
import Alert  from '../components/ui/Alert';

const REASONS = [
  { value: 'nouveau_resident', label: '🏠 Nouveau résident dans la ville' },
  { value: 'devenir_membre',   label: '⭐ Désir de devenir membre' },
  { value: 'vacances',         label: '✈️ De passage / Vacances' },
  { value: 'autres',           label: '✦ Autre raison' },
];

const FAMILY_ICONS = {
  Force: '⚡', Honneur: '🏅', Gloire: '✨', Louange: '🎵',
  Puissance: '💪', Richesse: '🌿', Sagesse: '📖',
};

const translateError = (msg = '') => {
  if (msg.includes('première') || msg.includes('deuxième')) return msg;
  if (msg.includes('required')) return 'Certains champs obligatoires sont manquants.';
  return 'Une erreur est survenue. Veuillez réessayer.';
};

const fmt = (d) => d
  ? new Date(d).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
  : '';

/* ─── Timeline des visites précédentes ───────────────────────────── */
const Timeline = ({ visitor }) => {
  const v1 = visitor?.visit1;
  const v2 = visitor?.visit2;
  if (!v1 && !v2) return null;

  return (
    <div className="rounded-xl border border-church-gold/30 bg-amber-50/70 p-4">
      <p className="text-xs font-body font-bold text-gray-500 uppercase tracking-widest mb-3">Votre parcours</p>
      <div className="flex flex-col gap-3">
        {v1 && (
          <div className="flex items-start gap-3">
            <div className="w-7 h-7 rounded-full bg-church-purple flex items-center justify-center text-white text-xs font-bold shrink-0 mt-0.5">1</div>
            <div>
              <p className="text-xs text-gray-400 font-body">{fmt(v1.date)}</p>
              <p className="text-sm font-semibold text-gray-700 font-body">{v1.fullName}</p>
              {v1.familleAccueil && (
                <p className="text-xs text-church-purple font-body mt-0.5">
                  {FAMILY_ICONS[v1.familleAccueil]} Famille {v1.familleAccueil}
                  {v1.invitedBy && <span className="text-gray-400 ml-2">· {v1.invitedBy}</span>}
                </p>
              )}
            </div>
          </div>
        )}
        {v1 && v2 && <div className="ml-3.5 w-px h-3 bg-church-gold/40" />}
        {v2 && (
          <div className="flex items-start gap-3">
            <div className="w-7 h-7 rounded-full bg-church-gold flex items-center justify-center text-white text-xs font-bold shrink-0 mt-0.5">2</div>
            <div>
              <p className="text-xs text-gray-400 font-body">{fmt(v2.date)}</p>
              <p className="text-sm font-semibold text-gray-700 font-body">{v2.fullName}</p>
              {v2.familleAccueil && (
                <p className="text-xs text-amber-700 font-body mt-0.5">
                  {FAMILY_ICONS[v2.familleAccueil]} Famille {v2.familleAccueil}
                </p>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

/* ─── Bloc nom — même pattern que Form2 ──────────────────────────── */
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
        {isModified && (
          <p className="text-xs text-amber-600 font-body mt-1">
            ⚠️ La correction sera appliquée sur toutes vos visites.
          </p>
        )}
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
          ⚠️ Cette correction sera appliquée sur toutes vos visites.
        </p>
      )}
      <div className="flex gap-2">
        <input type="text" value={value}
          onChange={e => onChange(e.target.value)}
          placeholder="Ex : Kouamé Jean-Pierre"
          className="church-input flex-1" autoFocus />
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
const Form3Page = ({ phone, visitor, onSuccess, onBack }) => {
  const prefillName = visitor?.visit1?.fullName || visitor?.visit2?.fullName || '';

  const [form, setForm] = useState({
    fullName:    prefillName,
    visitReason: '',
  });
  const [apiError, setApiError] = useState('');
  const [loading,  setLoading]  = useState(false);

  const handleSubmit = async () => {
    setApiError('');
    if (!form.fullName.trim()) { setApiError('Le nom est obligatoire.'); return; }
    if (!form.visitReason)     { setApiError('Veuillez sélectionner une raison.'); return; }

    setLoading(true);
    try {
      const { data } = await submitVisit3({
        phone,
        fullName:    form.fullName.trim(),
        visitReason: form.visitReason,
      });
      onSuccess(data.data?.visitor);
    } catch (err) {
      setApiError(translateError(err.response?.data?.message || err.message || ''));
    } finally {
      setLoading(false);
    }
  };

  return (
    <PageLayout visitNumber={3} title="Troisième Visite"
      subtitle="Votre fidélité nous touche ! Une dernière question pour mieux vous accompagner."
      onBack={onBack}>
      <div className="flex flex-col gap-5">

        <Timeline visitor={visitor} />

        <NameField
          value={form.fullName}
          prefill={prefillName}
          onChange={v => { setForm(p => ({ ...p, fullName: v })); if (apiError) setApiError(''); }}
        />

        {/* Raison principale */}
        <div>
          <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-2">
            Raison principale de vos visites <span className="text-church-gold">*</span>
          </p>
          <div className="flex flex-col gap-2">
            {REASONS.map(({ value, label }) => {
              const checked = form.visitReason === value;
              return (
                <button key={value} type="button"
                  onClick={() => { setForm(p => ({ ...p, visitReason: value })); if (apiError) setApiError(''); }}
                  className={`flex items-center gap-3 px-4 py-3 rounded-xl border-2 text-sm font-body
                              transition-all duration-200 text-left w-full
                              ${checked
                                ? 'border-church-purple bg-purple-50 text-church-purple font-semibold'
                                : 'border-gray-200 bg-white text-gray-700 hover:border-church-gold/40 hover:bg-amber-50/30'}`}>
                  <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 transition-all
                                   ${checked ? 'bg-church-purple border-church-purple' : 'border-gray-300'}`}>
                    {checked && <span className="w-2.5 h-2.5 rounded-full bg-white block" />}
                  </div>
                  {label}
                </button>
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

export default Form3Page;