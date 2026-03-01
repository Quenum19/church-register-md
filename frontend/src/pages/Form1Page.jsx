import { useState } from 'react';
import { submitVisit1 } from '../api/visitors';
import PageLayout from './PageLayout';
import Button from '../components/ui/Button';
import Input  from '../components/ui/Input';
import Alert  from '../components/ui/Alert';

/* ─── Constantes ─────────────────────────────────────────────────── */
const SOURCES = [
  { value: 'invite_membre',    label: '👥 Invité(e) par un membre', hasNameInput: true, hasCongregation: true },
  { value: 'saint_esprit',     label: '✨ Saint Esprit' },
  { value: 'reseaux_sociaux',  label: '📱 Réseaux sociaux' },
  { value: 'affiche_tract',    label: '📋 Affiche / Tract' },
  { value: 'bouche_a_oreille', label: '🗣️ Bouche à oreille' },
  { value: 'passage',          label: '🚶 Passage devant l\'église' },
  { value: 'autre',            label: '✦ Autre', hasOtherInput: true },
];

const CONGREGATIONS = ['Puissance', 'Richesse', 'Sagesse', 'Force', 'Honneur', 'Gloire', 'Louange'];

/* Indicatifs WhatsApp pour les étrangers */
const WA_COUNTRIES = [
  { dial: '+225', flag: '🇨🇮' },
  { dial: '+221', flag: '🇸🇳' },
  { dial: '+223', flag: '🇲🇱' },
  { dial: '+226', flag: '🇧🇫' },
  { dial: '+233', flag: '🇬🇭' },
  { dial: '+237', flag: '🇨🇲' },
  { dial: '+33',  flag: '🇫🇷' },
  { dial: '+32',  flag: '🇧🇪' },
  { dial: '+1',   flag: '🇺🇸' },
  { dial: '+',    flag: '🌍'  },
];

/* ─── Traduction erreurs ──────────────────────────────────────────── */
const translateError = (msg = '') => {
  if (msg.includes('enum'))      return 'Une option sélectionnée n\'est pas reconnue. Veuillez réessayer.';
  if (msg.includes('required'))  return 'Certains champs obligatoires sont manquants.';
  if (msg.includes('duplicate')) return 'Ce numéro est déjà enregistré.';
  if (msg.includes('déjà'))      return msg;
  return 'Une erreur est survenue. Veuillez réessayer.';
};

const validate = (form) => {
  if (!form.fullName.trim())     return 'Le nom et prénoms sont obligatoires.';
  if (!form.city.trim())         return 'Le domicile (commune) est obligatoire.';
  if (!form.neighborhood.trim()) return 'Le quartier est obligatoire.';
  if (form.inviteSource === 'invite_membre' && !form.invitedBy.trim())
    return 'Veuillez indiquer le nom du membre qui vous a invité(e).';
  if (form.inviteSource === 'autre' && !form.inviteSourceOther.trim())
    return 'Veuillez préciser comment vous nous avez connus.';
  return null;
};

/* ─── Sous-composants ─────────────────────────────────────────────── */
const FieldLabel = ({ children, required }) => (
  <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-2">
    {children}{required && <span className="text-church-gold ml-1">*</span>}
  </p>
);

/* Chip source radio avec toggle */
const SourceChip = ({ source, selected, disabled, onSelect }) => (
  <button type="button" onClick={() => onSelect(source.value)} disabled={disabled}
    className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 text-sm font-body
                transition-all duration-200 text-left
                ${selected  ? 'border-church-gold bg-amber-50 text-amber-800 font-semibold'
                : disabled  ? 'border-gray-100 bg-gray-50 text-gray-300 cursor-not-allowed'
                            : 'border-gray-200 bg-white text-gray-700 hover:border-church-purple/40 hover:bg-purple-50/30'}`}>
    <div className={`w-4 h-4 rounded-full border-2 shrink-0 flex items-center justify-center transition-all
                     ${selected ? 'border-church-gold bg-church-gold' : 'border-gray-300'}`}>
      {selected && <span className="w-2 h-2 rounded-full bg-white block" />}
    </div>
    {source.label}
  </button>
);

/* Champ WhatsApp avec mini-sélecteur pays */
const WhatsAppInput = ({ value, onChange }) => {
  const [dialCode, setDialCode] = useState('+225');
  const [open, setOpen]         = useState(false);
  const selected = WA_COUNTRIES.find(c => c.dial === dialCode) || WA_COUNTRIES[0];

  const handleChange = (e) => {
    const digits = e.target.value.replace(/[^0-9 ]/g, '');
    onChange(dialCode + digits.replace(/\s/g, ''));
  };

  const localValue = value?.startsWith(dialCode)
    ? value.slice(dialCode.length)
    : value?.replace(/^\+\d{1,4}/, '') || '';

  return (
    <div className="flex gap-1.5">
      {/* Mini sélecteur indicatif */}
      <div className="relative">
        <button type="button" onClick={() => setOpen(o => !o)}
          className="flex items-center gap-1 px-2 py-3 rounded-xl border-2 border-gray-200 bg-white
                     hover:border-church-gold transition-all text-sm font-body whitespace-nowrap">
          <span>{selected.flag}</span>
          <span className="text-gray-600 text-xs font-semibold">{selected.dial}</span>
          <svg className={`w-2.5 h-2.5 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`}
               fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        {open && (
          <div className="absolute top-full left-0 mt-1 z-50 bg-white rounded-xl shadow-lg border border-gray-100 min-w-[120px]">
            {WA_COUNTRIES.map(c => (
              <button key={c.dial} type="button"
                onClick={() => { setDialCode(c.dial); setOpen(false); onChange(''); }}
                className="w-full flex items-center gap-2 px-3 py-2 text-sm font-body text-gray-700
                           hover:bg-purple-50 transition-colors text-left">
                <span>{c.flag}</span><span className="text-xs text-gray-500">{c.dial}</span>
              </button>
            ))}
          </div>
        )}
      </div>
      <input type="tel" inputMode="numeric" placeholder="07 00 00 00 00"
        value={localValue}
        onChange={handleChange}
        className="church-input flex-1" />
    </div>
  );
};

/* ─── Composant principal ─────────────────────────────────────────── */
const Form1Page = ({ phone, onSuccess, onBack }) => {
  const [form, setForm] = useState({
    fullName: '', whatsapp: '', contactPhone: '',
    city: '', neighborhood: '',
    inviteSource: '', invitedBy: '', inviteSourceOther: '', congregation: '',
    wantsWhatsAppGroup: false,
  });
  const [apiError, setApiError] = useState('');
  const [loading,  setLoading]  = useState(false);

  const setField = (field, value) => {
    setForm(p => ({ ...p, [field]: value }));
    if (apiError) setApiError('');
  };

  const handleSourceSelect = (value) => {
    const next = form.inviteSource === value ? '' : value;
    setForm(p => ({ ...p, inviteSource: next, invitedBy: '', inviteSourceOther: '', congregation: '' }));
    if (apiError) setApiError('');
  };

  const handleSubmit = async () => {
    setApiError('');
    const err = validate(form);
    if (err) { setApiError(err); return; }

    const payload = {
      phone,
      fullName:      form.fullName.trim(),
      whatsapp:      form.whatsapp || undefined,
      contactPhone:  form.contactPhone.trim() || undefined,
      city:          form.city.trim(),
      neighborhood:  form.neighborhood.trim(),
      inviteSource:  form.inviteSource || undefined,
      invitedBy:     form.inviteSource === 'invite_membre' ? form.invitedBy.trim()         : undefined,
      congregation:  form.inviteSource === 'invite_membre' ? form.congregation             : undefined,
      notes:         form.inviteSource === 'autre'         ? form.inviteSourceOther.trim() : undefined,
      wantsWhatsAppGroup: form.wantsWhatsAppGroup,
    };

    setLoading(true);
    try {
      const { data } = await submitVisit1(payload);
      onSuccess(data.data?.visitor);
    } catch (err) {
      setApiError(translateError(err.response?.data?.message || err.message || ''));
    } finally {
      setLoading(false);
    }
  };

  const selectedSource = SOURCES.find(s => s.value === form.inviteSource);

  return (
    <PageLayout visitNumber={1} title="Première Visite"
      subtitle="Ravi de vous accueillir ! Quelques informations pour mieux vous connaître."
      onBack={onBack}>
      <div className="flex flex-col gap-5">

        {/* Identité */}
        <Input label="Nom et Prénoms" required placeholder="Ex : Kouamé Jean-Pierre"
          value={form.fullName} onChange={e => setField('fullName', e.target.value)} />

        {/* Contacts */}
        <div>
          <FieldLabel>WhatsApp</FieldLabel>
          <WhatsAppInput value={form.whatsapp} onChange={v => setField('whatsapp', v)} />
        </div>

        <Input label="Autre contact" type="tel" placeholder="Ex : 05 00 00 00 00"
          value={form.contactPhone} onChange={e => setField('contactPhone', e.target.value)} />

        {/* Localisation — "Domicile (commune)" au lieu de "Ville" */}
        <div className="grid grid-cols-2 gap-4">
          <Input label="Domicile (commune)" required placeholder="Ex : Cocody"
            value={form.city} onChange={e => setField('city', e.target.value)} />
          <Input label="Quartier" required placeholder="Ex : Angré"
            value={form.neighborhood} onChange={e => setField('neighborhood', e.target.value)} />
        </div>

        {/* Source de connaissance */}
        <div>
          <FieldLabel>Comment nous avez-vous connus ?</FieldLabel>
          <div className="flex flex-col gap-2">
            {SOURCES.map(source => (
              <SourceChip key={source.value} source={source}
                selected={form.inviteSource === source.value}
                disabled={!!form.inviteSource && form.inviteSource !== source.value}
                onSelect={handleSourceSelect} />
            ))}
          </div>
          {form.inviteSource && (
            <button type="button" onClick={() => handleSourceSelect(form.inviteSource)}
              className="mt-2 text-xs text-church-purple font-body font-semibold
                         hover:text-church-gold-dk underline underline-offset-2 transition-colors">
              ↩ Modifier mon choix
            </button>
          )}
        </div>

        {/* Nom du membre invitant */}
        {selectedSource?.hasNameInput && (
          <div className="animate-fade-up">
            <Input label="Nom et prénom du membre" required
              placeholder="Ex : Pastor Koffi Adjoua"
              value={form.invitedBy}
              onChange={e => setField('invitedBy', e.target.value)} />
          </div>
        )}

        {/* Congrégation */}
        {selectedSource?.hasCongregation && (
          <div className="animate-fade-up">
            <FieldLabel>Congrégation du membre</FieldLabel>
            <div className="flex flex-wrap gap-2">
              {CONGREGATIONS.map(cong => (
                <button key={cong} type="button"
                  onClick={() => setField('congregation', form.congregation === cong ? '' : cong)}
                  className={`px-4 py-2 rounded-full border-2 text-sm font-body font-semibold transition-all duration-150
                    ${form.congregation === cong
                      ? 'border-church-purple bg-church-purple text-white shadow-sm'
                      : 'border-gray-200 bg-white text-gray-700 hover:border-church-purple/50 hover:text-church-purple'}`}>
                  {cong}
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Précision "Autre" */}
        {selectedSource?.hasOtherInput && (
          <div className="animate-fade-up">
            <FieldLabel required>Précisez comment vous nous avez connus</FieldLabel>
            <textarea placeholder="Décrivez en quelques mots..."
              value={form.inviteSourceOther}
              onChange={e => setField('inviteSourceOther', e.target.value)}
              rows={2} className="church-input resize-none" />
          </div>
        )}

        {/* Groupe WhatsApp */}
        <button type="button"
          onClick={() => setField('wantsWhatsAppGroup', !form.wantsWhatsAppGroup)}
          className={`flex items-center gap-3 px-4 py-3 rounded-xl border-2 transition-all duration-200 text-left w-full
            ${form.wantsWhatsAppGroup
              ? 'border-church-purple bg-purple-50/60'
              : 'border-gray-200 bg-white hover:border-church-purple/30'}`}>
          <div className={`w-5 h-5 rounded border-2 flex items-center justify-center shrink-0 transition-all
                           ${form.wantsWhatsAppGroup ? 'bg-church-purple border-church-purple' : 'border-gray-300'}`}>
            {form.wantsWhatsAppGroup && <span className="text-white text-xs font-bold">✓</span>}
          </div>
          <span className="font-body text-sm text-gray-700">
            Rejoindre le <strong className="text-church-purple">groupe WhatsApp</strong> de l'église
          </span>
        </button>

        {apiError && <Alert type="error" message={apiError} />}
        <Button onClick={handleSubmit} disabled={loading}>Valider ma visite ✦</Button>
      </div>
    </PageLayout>
  );
};

export default Form1Page;