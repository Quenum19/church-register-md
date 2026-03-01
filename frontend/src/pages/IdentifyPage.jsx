import { useState, useRef } from 'react';
import { identifyVisitor } from '../api/visitors';
import Button from '../components/ui/Button';
import Alert  from '../components/ui/Alert';
import logo   from '../assets/logo_md.png';

/* ─── Pays fréquents (Afrique de l'Ouest + international) ────────── */
const COUNTRIES = [
  { code: 'CI', flag: '🇨🇮', name: "Côte d'Ivoire",   dial: '+225', pattern: /^[0-9]{10}$/,        placeholder: '07 00 00 00 00',  digits: 10 },
  { code: 'SN', flag: '🇸🇳', name: 'Sénégal',          dial: '+221', pattern: /^[0-9]{9}$/,         placeholder: '77 000 00 00',    digits: 9  },
  { code: 'ML', flag: '🇲🇱', name: 'Mali',             dial: '+223', pattern: /^[0-9]{8}$/,         placeholder: '70 00 00 00',     digits: 8  },
  { code: 'BF', flag: '🇧🇫', name: 'Burkina Faso',     dial: '+226', pattern: /^[0-9]{8}$/,         placeholder: '70 00 00 00',     digits: 8  },
  { code: 'GH', flag: '🇬🇭', name: 'Ghana',            dial: '+233', pattern: /^[0-9]{9,10}$/,      placeholder: '24 000 0000',     digits: 10 },
  { code: 'TG', flag: '🇹🇬', name: 'Togo',             dial: '+228', pattern: /^[0-9]{8}$/,         placeholder: '90 00 00 00',     digits: 8  },
  { code: 'BJ', flag: '🇧🇯', name: 'Bénin',            dial: '+229', pattern: /^[0-9]{8}$/,         placeholder: '97 00 00 00',     digits: 8  },
  { code: 'GN', flag: '🇬🇳', name: 'Guinée',           dial: '+224', pattern: /^[0-9]{9}$/,         placeholder: '620 00 00 00',    digits: 9  },
  { code: 'CM', flag: '🇨🇲', name: 'Cameroun',         dial: '+237', pattern: /^[0-9]{9}$/,         placeholder: '670 000 000',     digits: 9  },
  { code: 'CD', flag: '🇨🇩', name: 'Congo (RDC)',      dial: '+243', pattern: /^[0-9]{9}$/,         placeholder: '812 345 678',     digits: 9  },
  { code: 'GA', flag: '🇬🇦', name: 'Gabon',            dial: '+241', pattern: /^[0-9]{7,8}$/,       placeholder: '06 00 00 00',     digits: 8  },
  { code: 'FR', flag: '🇫🇷', name: 'France',           dial: '+33',  pattern: /^[0-9]{9}$/,         placeholder: '6 00 00 00 00',   digits: 9  },
  { code: 'BE', flag: '🇧🇪', name: 'Belgique',         dial: '+32',  pattern: /^[0-9]{9}$/,         placeholder: '470 00 00 00',    digits: 9  },
  { code: 'US', flag: '🇺🇸', name: 'États-Unis',       dial: '+1',   pattern: /^[0-9]{10}$/,        placeholder: '555 000 0000',    digits: 10 },
  { code: 'OTHER', flag: '🌍', name: 'Autre pays',     dial: '+',    pattern: /^[0-9]{6,15}$/,      placeholder: '000 000 0000',    digits: 15 },
];

/* Étoiles décoratives (déterministes) */
const STARS = Array.from({ length: 18 }, (_, i) => ({
  id: i,
  top:   `${5 + (i * 37) % 88}%`,
  left:  `${5 + (i * 53) % 88}%`,
  delay: `${((i * 0.7) % 4).toFixed(1)}s`,
  size:  `${2 + (i % 3)}px`,
}));

/* ─── Composant sélecteur de pays ────────────────────────────────── */
const CountrySelector = ({ selected, onSelect }) => {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  // Fermer au clic extérieur
  const handleBlur = (e) => {
    if (!ref.current?.contains(e.relatedTarget)) setOpen(false);
  };

  return (
    <div className="relative" ref={ref} onBlur={handleBlur}>
      <button
        type="button"
        onClick={() => setOpen(o => !o)}
        className="flex items-center gap-2 px-3 py-3 rounded-xl border-2 border-gray-200 bg-white
                   hover:border-church-gold transition-all duration-200 text-sm font-body whitespace-nowrap
                   focus:outline-none focus:border-church-gold"
      >
        <span className="text-base">{selected.flag}</span>
        <span className="text-gray-700 font-semibold">{selected.dial}</span>
        <svg className={`w-3 h-3 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`}
             fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {open && (
        <div className="absolute top-full left-0 mt-1 z-50 bg-white rounded-2xl shadow-card border border-gray-100
                        max-h-64 overflow-y-auto min-w-[220px]">
          {COUNTRIES.map(c => (
            <button
              key={c.code}
              type="button"
              tabIndex={0}
              onClick={() => { onSelect(c); setOpen(false); }}
              className={`w-full flex items-center gap-3 px-4 py-2.5 text-sm font-body text-left
                          hover:bg-purple-50 transition-colors
                          ${selected.code === c.code ? 'bg-amber-50 font-semibold text-church-gold-dk' : 'text-gray-700'}`}
            >
              <span className="text-base w-6 text-center">{c.flag}</span>
              <span className="flex-1">{c.name}</span>
              <span className="text-gray-400 text-xs">{c.dial}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

/* ─── Composant principal ─────────────────────────────────────────── */
const IdentifyPage = ({ onIdentified }) => {
  const [country,  setCountry]  = useState(COUNTRIES[0]); // CI par défaut
  const [phone,    setPhone]    = useState('');
  const [loading,  setLoading]  = useState(false);
  const [error,    setError]    = useState('');
  const [confirm,  setConfirm]  = useState(false); // étape de confirmation
  const [done,     setDone]     = useState(false);

  /* Numéro complet = indicatif + numéro saisi */
  const fullPhone = `${country.dial}${phone.replace(/\s/g, '')}`;

  /* Validation format */
  const rawDigits = phone.replace(/\s/g, '');
  const isValid   = country.pattern.test(rawDigits);

  const handleChange = (e) => {
    // N'accepter que chiffres et espaces
    const val = e.target.value.replace(/[^0-9 ]/g, '');
    setPhone(val);
    setError('');
    setConfirm(false);
  };

  const handleContinue = () => {
    setError('');
    if (!rawDigits) { setError('Veuillez entrer un numéro de téléphone.'); return; }
    if (!isValid) {
      setError(`Format incorrect pour ${country.name}. Ce numéro doit contenir ${country.digits} chiffres.`);
      return;
    }
    // Passer en mode confirmation
    setConfirm(true);
  };

  const handleConfirm = async () => {
    setLoading(true);
    setError('');
    try {
      const { data } = await identifyVisitor(fullPhone);
      const { nextForm, visitor } = data.data;

      if (nextForm === null) { setDone(true); return; }
      onIdentified({ phone: fullPhone, nextForm, visitor });
    } catch (err) {
      setError(err.response?.data?.message || 'Erreur de connexion. Réessayez.');
      setConfirm(false);
    } finally {
      setLoading(false);
    }
  };

  /* ── Écran "parcours complet" ── */
  if (done) {
    return (
      <div className="min-h-screen bg-church-animated flex items-center justify-center p-4 relative overflow-hidden">
        <Stars />
        <div className="church-card corner-ornament relative w-full max-w-md p-10 text-center animate-fade-up">
          <div className="text-5xl mb-4">🙏</div>
          <h2 className="font-display text-2xl font-bold text-church-purple mb-3">
            Merci pour votre fidélité !
          </h2>
          <div className="gold-divider my-4" />
          <p className="text-gray-600 font-body text-sm leading-relaxed">
            Vous avez complété vos 3 premières visites.<br />
            <strong className="text-church-purple">Approchez un responsable</strong> pour la prochaine étape !
          </p>
          <button onClick={() => setDone(false)}
            className="mt-6 text-sm text-church-purple font-body font-semibold hover:underline transition-all">
            ← Retour à l'accueil
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-church-animated flex items-center justify-center p-4 relative overflow-hidden">
      <Stars />

      <div className="absolute top-[-100px] left-[-100px] w-96 h-96 rounded-full opacity-20 pointer-events-none"
           style={{ background: 'radial-gradient(circle, #C9A227 0%, transparent 70%)' }} />
      <div className="absolute bottom-[-80px] right-[-80px] w-80 h-80 rounded-full opacity-15 pointer-events-none"
           style={{ background: 'radial-gradient(circle, #8B35AA 0%, transparent 70%)' }} />

      <div className="church-card corner-ornament relative w-full max-w-md overflow-hidden">
        <div className="h-1.5 w-full bg-gold-gradient" />

        <div className="p-8 md:p-10">

          {/* Logo */}
          <div className="text-center mb-8 opacity-init animate-fade-up">
            <div className="relative inline-block mb-5">
              <div className="absolute inset-[-8px] rounded-full animate-glow pointer-events-none"
                   style={{ background: 'radial-gradient(circle, rgba(201,162,39,0.25) 0%, transparent 70%)' }} />
              <img src={logo} alt="La Maison de la Destinée"
                   className="w-24 h-24 object-contain relative z-10 drop-shadow-lg" />
            </div>
            <h1 className="font-display text-2xl font-bold text-church-purple leading-tight">
              Eglise La Maison<br />
              <span className="text-transparent bg-clip-text bg-gold-gradient">de la Destinée</span>
            </h1>
            <div className="gold-divider my-4" />
            <p className="text-gray-500 font-body text-sm">
              Bienvenue ! Entrez votre numéro pour commencer.
            </p>
          </div>

          {/* ── Formulaire ── */}
          {!confirm ? (
            <div className="flex flex-col gap-4 opacity-init animate-fade-up-d2">

              {/* Sélecteur pays + champ téléphone */}
              <div>
                <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-2">
                  Numéro de téléphone <span className="text-church-gold">*</span>
                </p>
                <div className="flex gap-2">
                  <CountrySelector selected={country} onSelect={(c) => { setCountry(c); setPhone(''); setError(''); setConfirm(false); }} />
                  <input
                    type="tel"
                    inputMode="numeric"
                    value={phone}
                    onChange={handleChange}
                    onKeyDown={(e) => e.key === 'Enter' && handleContinue()}
                    placeholder={country.placeholder}
                    maxLength={country.digits + 3} // espaces tolérés
                    className={`church-input flex-1 transition-all duration-200
                      ${rawDigits && !isValid ? 'border-red-300 focus:border-red-400' : ''}
                      ${rawDigits && isValid  ? 'border-green-400 focus:border-green-500' : ''}`}
                  />
                </div>

                {/* Indicateur format en temps réel */}
                {rawDigits.length > 0 && (
                  <div className="flex items-center gap-1.5 mt-1.5">
                    <span className={`text-xs font-body ${isValid ? 'text-green-600' : 'text-orange-500'}`}>
                      {isValid
                        ? `✓ Format valide — ${country.name}`
                        : `${rawDigits.length} / ${country.digits} chiffres attendus`}
                    </span>
                  </div>
                )}
              </div>

              {error && <Alert type="error" message={error} />}

              <button type="button" onClick={handleContinue}
                className="btn-gold w-full text-center">
                Continuer →
              </button>
            </div>

          ) : (
            /* ── Étape de confirmation ── */
            <div className="flex flex-col gap-4 opacity-init animate-fade-up">

              <div className="px-5 py-4 rounded-2xl bg-amber-50 border-2 border-church-gold/40 text-center">
                <p className="text-xs text-gray-500 font-body uppercase tracking-widest mb-1">
                  Votre numéro
                </p>
                <p className="font-display text-2xl font-bold text-church-purple-dk tracking-wide">
                  {country.flag} {fullPhone}
                </p>
                <p className="text-xs text-gray-500 font-body mt-1">{country.name}</p>
              </div>

              <Alert type="warning"
                message="⚠️ Vérifiez bien ce numéro — c'est votre identifiant pour toutes vos visites. En cas d'erreur, vous ne pourrez pas être reconnu(e) les prochains dimanches." />

              <div className="flex gap-3">
                <button type="button" onClick={() => setConfirm(false)}
                  className="flex-1 px-4 py-3 rounded-xl border-2 border-gray-200 text-gray-600 font-body
                             font-semibold text-sm hover:border-church-purple/40 hover:text-church-purple
                             transition-all duration-200">
                  ← Modifier
                </button>
                <button type="button" onClick={handleConfirm} disabled={loading}
                  className="flex-2 flex-grow btn-gold text-center">
                  {loading
                    ? <span className="flex items-center justify-center gap-2">
                        <svg className="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        Vérification…
                      </span>
                    : 'Oui, c\'est mon numéro ✓'}
                </button>
              </div>
            </div>
          )}

          <p className="text-center text-xs text-gray-400 font-body mt-6 opacity-init animate-fade-up-d4">
            🔒 Vos données sont confidentielles et utilisées uniquement par l'église.
          </p>
        </div>

        <div className="h-1 w-full bg-gold-gradient opacity-60" />
      </div>
    </div>
  );
};

const Stars = () => (
  <div className="stars-bg">
    {STARS.map(s => (
      <span key={s.id} className="star" style={{
        top: s.top, left: s.left, width: s.size, height: s.size, animationDelay: s.delay,
      }} />
    ))}
  </div>
);

export default IdentifyPage;