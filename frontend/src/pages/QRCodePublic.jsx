import { useEffect, useState } from 'react';

const API_BASE = process.env.REACT_APP_API_URL || 'http://localhost:5000/api';

const VERSETS_FALLBACK = [
  { ref: 'Jean 21:17', text: 'Si tu m\'aimes, pais mes brebis.' },
  { ref: 'Jean 3:16',     text: 'Car Dieu a tant aimé le monde qu\'il a donné son Fils unique, afin que quiconque croit en lui ne périsse point, mais qu\'il ait la vie éternelle.' },
  { ref: 'Psaumes 23:1',  text: 'L\'Éternel est mon berger : je ne manquerai de rien.' },
];

const QRCodePublic = () => {
  const [qrDataURL, setQrDataURL] = useState('');
  const [verset,    setVerset]    = useState(null);
  const [loading,   setLoading]   = useState(true);

  useEffect(() => {
    // Garder l'écran allumé
    if ('wakeLock' in navigator) {
      navigator.wakeLock.request('screen').catch(() => {});
    }

    // Priorité 1 : config en base (via /api/qrcode/public)
    fetch(`${API_BASE}/qrcode/public`)
      .then(r => r.json())
      .then(data => {
        if (data.status === 'success') {
          setQrDataURL(data.data.qrDataURL);
          setVerset(data.data.verset);
        } else {
          throw new Error('Config indisponible');
        }
      })
      .catch(() => {
        // Priorité 2 : fallback sur ?v=index dans l'URL
        const params = new URLSearchParams(window.location.search);
        const v      = parseInt(params.get('v') ?? '0');
        setVerset(VERSETS_FALLBACK[v] ?? VERSETS_FALLBACK[0]);
      })
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="min-h-screen flex flex-col items-center justify-between relative overflow-hidden"
         style={{ background: 'linear-gradient(160deg, #3D0A5A 0%, #6B1F8A 55%, #4A0E6B 100%)' }}>

      {/* Cercles déco */}
      <div className="absolute top-[-80px] right-[-80px] w-72 h-72 rounded-full pointer-events-none"
           style={{ background: 'radial-gradient(circle, rgba(201,162,39,0.12), transparent)' }} />
      <div className="absolute bottom-[-60px] left-[-60px] w-56 h-56 rounded-full pointer-events-none"
           style={{ background: 'radial-gradient(circle, rgba(201,162,39,0.10), transparent)' }} />

      {/* Bande dorée top */}
      <div className="w-full h-1.5 shrink-0"
           style={{ background: 'linear-gradient(90deg, #9B7A10, #C9A227, #E8C547, #C9A227, #9B7A10)' }} />

      {/* Contenu */}
      <div className="flex-1 flex flex-col items-center justify-center px-6 py-8 w-full max-w-sm mx-auto">

        {/* Logo + Titre */}
        <div className="text-center mb-8">
          <div className="w-16 h-16 rounded-full bg-white/10 border border-white/20
                          flex items-center justify-center mx-auto mb-4">
            <span style={{ fontSize: 32 }}>🏛️</span>
          </div>
          <h1 className="font-display text-2xl font-bold text-white leading-tight">
            La Maison de la Destinée
          </h1>
          <div style={{
            height: 2, width: 80,
            background: 'linear-gradient(90deg, transparent, #C9A227, transparent)',
            margin: '10px auto',
          }} />
          <p className="text-white/60 text-sm font-body">Bienvenue parmi nous 🙏</p>
        </div>

        {/* Instruction */}
        <div className="text-center mb-6">
          <p className="text-white font-body font-bold text-base">
            📱 Scannez pour vous inscrire
          </p>
          <p className="text-white/50 text-xs font-body mt-1">
            Ouvrez l'appareil photo et pointez vers le code
          </p>
        </div>

        {/* QR Code */}
        <div style={{
          padding: 16, background: '#fff', borderRadius: 20,
          boxShadow: '0 16px 60px rgba(0,0,0,0.35)',
          border: '3px solid rgba(201,162,39,0.3)',
        }}>
          {loading ? (
            <div style={{ width: 240, height: 240, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <svg className="animate-spin w-8 h-8 text-church-purple" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
            </div>
          ) : qrDataURL ? (
            <img src={qrDataURL} alt="QR Code Maison de la Destinée"
                 style={{ width: 240, height: 240, display: 'block' }} />
          ) : (
            <div style={{ width: 240, height: 240, display: 'flex', alignItems: 'center',
                          justifyContent: 'center', flexDirection: 'column', gap: 8 }}>
              <span style={{ fontSize: 32 }}>⚠️</span>
              <p style={{ color: '#6b7280', fontSize: 12, textAlign: 'center' }}>
                Erreur de chargement.<br/>Vérifiez la connexion.
              </p>
            </div>
          )}
        </div>
      </div>

      {/* Verset */}
      {verset?.text && (
        <div className="w-full px-8 pb-6 pt-4 text-center shrink-0"
             style={{ borderTop: '1px solid rgba(201,162,39,0.2)' }}>
          <p className="text-white/60 text-xs font-body italic leading-relaxed">
            « {verset.text} »
          </p>
          <p className="text-xs font-body font-bold mt-1.5" style={{ color: '#C9A227' }}>
            — {verset.ref}
          </p>
        </div>
      )}

      {/* Bande dorée bottom */}
      <div className="w-full h-1.5 shrink-0"
           style={{ background: 'linear-gradient(90deg, #9B7A10, #C9A227, #E8C547, #C9A227, #9B7A10)' }} />
    </div>
  );
};

export default QRCodePublic;