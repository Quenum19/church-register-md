import { useEffect, useState } from 'react';
import { useAuth } from '../../context/AuthContext';

const API_BASE    = process.env.REACT_APP_API_URL || 'http://localhost:5000/api';
const authHeaders = () => ({
  'Content-Type': 'application/json',
  Authorization:  `Bearer ${localStorage.getItem('token')}`,
});

const VERSETS = [
  { ref: 'Jean 21:17', text: 'Si tu m\'aimes, pais mes brebis.' },
  { ref: 'Jean 3:16',     text: 'Car Dieu a tant aimé le monde qu\'il a donné son Fils unique, afin que quiconque croit en lui ne périsse point, mais qu\'il ait la vie éternelle.' },
  { ref: 'Psaumes 23:1',  text: 'L\'Éternel est mon berger : je ne manquerai de rien.' },
];

/* ── Charger html2canvas dynamiquement ──────────────────────────── */
const loadHtml2Canvas = () => new Promise((resolve, reject) => {
  if (window.html2canvas) { resolve(window.html2canvas); return; }
  const s = document.createElement('script');
  s.src     = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
  s.onload  = () => resolve(window.html2canvas);
  s.onerror = reject;
  document.head.appendChild(s);
});

/* ── Affiche complète (rendue dans le DOM puis capturée) ─────────── */
const QRAffiche = ({ id = 'qr-affiche', qrDataURL, verset }) => (
  <div id={id}
    style={{
      background: '#ffffff', width: '100%', borderRadius: 24,
      overflow: 'hidden', fontFamily: "'Segoe UI', Arial, sans-serif",
      boxShadow: '0 8px 40px rgba(74,14,107,0.15)',
    }}>

    {/* Bande dorée top */}
    <div style={{ height: 10, background: 'linear-gradient(90deg, #9B7A10, #C9A227, #E8C547, #C9A227, #9B7A10)' }} />

    {/* Header violet */}
    <div style={{
      background: 'linear-gradient(160deg, #3D0A5A 0%, #6B1F8A 50%, #4A0E6B 100%)',
      padding: '32px 40px', textAlign: 'center', position: 'relative',
    }}>
      <div style={{
        width: 64, height: 64, borderRadius: '50%',
        background: 'rgba(255,255,255,0.12)', border: '2px solid rgba(201,162,39,0.5)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        margin: '0 auto 12px', fontSize: 28,
      }}>🏛️</div>
      <h1 style={{
        color: '#fff', fontSize: 22, fontWeight: 800,
        margin: '0 0 8px', letterSpacing: 0.5,
      }}>La Maison de la Destinée</h1>
      <div style={{
        height: 2, width: 80,
        background: 'linear-gradient(90deg, transparent, #C9A227, transparent)',
        margin: '0 auto 10px',
      }} />
      <p style={{ color: 'rgba(255,255,255,0.65)', fontSize: 13, margin: 0, fontStyle: 'italic' }}>
        Bienvenue parmi nous 🙏
      </p>
    </div>

    {/* Instruction */}
    <div style={{ background: '#f9f7ff', padding: '20px 32px', textAlign: 'center' }}>
      <p style={{ fontSize: 16, fontWeight: 700, color: '#3D0A5A', margin: '0 0 4px' }}>
        📱 Scannez ce code pour vous inscrire
      </p>
      <p style={{ fontSize: 11, color: '#9ca3af', margin: 0 }}>
        Ouvrez l'appareil photo de votre téléphone et pointez-le vers le QR code
      </p>
    </div>

    {/* QR Code */}
    <div style={{ padding: '28px 0', display: 'flex', justifyContent: 'center', background: '#fff' }}>
      {qrDataURL && (
        <div style={{
          padding: 14, background: '#fff', borderRadius: 18,
          boxShadow: '0 6px 32px rgba(74,14,107,0.12)',
          border: '2px solid #f0ebff',
        }}>
          <img src={qrDataURL} alt="QR Code"
               style={{ width: 220, height: 220, display: 'block' }} />
        </div>
      )}
    </div>

    {/* Verset */}
    {verset?.text && (
      <div style={{
        padding: '20px 40px 24px', borderTop: '2px solid #f0ebff',
        textAlign: 'center', background: '#fff',
      }}>
        <p style={{
          fontSize: 13, fontStyle: 'italic', color: '#6b7280',
          lineHeight: 1.7, margin: '0 0 8px',
        }}>« {verset.text} »</p>
        <p style={{ fontSize: 12, fontWeight: 700, color: '#C9A227', margin: 0, letterSpacing: 0.5 }}>
          — {verset.ref}
        </p>
      </div>
    )}

    {/* Bande dorée bottom */}
    <div style={{ height: 10, background: 'linear-gradient(90deg, #9B7A10, #C9A227, #E8C547, #C9A227, #9B7A10)' }} />
  </div>
);

/* ── Composant principal ─────────────────────────────────────────── */
const QRCodePage = () => {
  const { isSuperAdmin } = useAuth();
  const [qrDataURL,      setQrDataURL]      = useState('');
  const [qrUrl,          setQrUrl]          = useState('');
  const [loading,        setLoading]        = useState(true);
  const [downloading,    setDownloading]    = useState(false);
  const [saving,         setSaving]         = useState(false);
  const [fullscreen,     setFullscreen]     = useState(false);
  const [selectedVerset, setSelectedVerset] = useState(0);
  const [useCustom,      setUseCustom]      = useState(false);
  const [customVerset,   setCustomVerset]   = useState({ ref: '', text: '' });
  const [toast,          setToast]          = useState('');

  const showToast = (msg) => { setToast(msg); setTimeout(() => setToast(''), 3500); };

  // URL publique toujours courte et fixe
  const publicUrl = `${window.location.origin}/qrcode`;

  useEffect(() => {
    fetch(`${API_BASE}/qrcode`, { headers: authHeaders() })
      .then(r => r.json())
      .then(data => {
        setQrDataURL(data.data.qrDataURL);
        setQrUrl(data.data.url);
        // Charger le verset actif depuis la base
        return fetch(`${API_BASE}/qrcode/config`, { headers: authHeaders() });
      })
      .then(r => r.json())
      .then(data => {
        if (data.status === 'success') {
          const cfg = data.data.config;
          if (cfg.versetIndex >= 0) {
            setSelectedVerset(cfg.versetIndex);
            setUseCustom(false);
          } else {
            setUseCustom(true);
            setCustomVerset({ ref: cfg.ref, text: cfg.text });
          }
        }
      })
      .catch(() => showToast('❌ Erreur chargement QR code'))
      .finally(() => setLoading(false));
  }, []);

  const currentVerset = useCustom ? customVerset : VERSETS[selectedVerset];

  /* ── Sauvegarder le verset actif en base ── */
  const handleApplyVerset = async () => {
    setSaving(true);
    try {
      const body = useCustom
        ? { versetIndex: -1, ref: customVerset.ref, text: customVerset.text }
        : { versetIndex: selectedVerset };
      const res  = await fetch(`${API_BASE}/qrcode/config`, {
        method: 'PUT', headers: authHeaders(),
        body: JSON.stringify(body),
      });
      const data = await res.json();
      if (data.status === 'success') showToast('✅ Verset mis à jour — la page accueillantes est synchronisée !');
      else showToast(`❌ ${data.message}`);
    } catch (e) {
      showToast('❌ Erreur de sauvegarde.');
    } finally {
      setSaving(false);
    }
  };

  /* ── Téléchargement affiche complète via html2canvas ── */
  const handleDownloadAffiche = async () => {
    setDownloading(true);
    showToast('⏳ Génération de l\'image en cours…');
    try {
      const html2canvas = await loadHtml2Canvas();
      const element     = document.getElementById('qr-affiche');
      if (!element) throw new Error('Élément introuvable');

      const canvas = await html2canvas(element, {
        scale:           2,          // 2x pour haute résolution
        useCORS:         true,
        backgroundColor: '#ffffff',
        logging:         false,
      });

      // Télécharger
      const link    = document.createElement('a');
      link.download = 'affiche-qrcode-maison-destinee.png';
      link.href     = canvas.toDataURL('image/png');
      link.click();
      showToast('✅ Affiche téléchargée en haute résolution !');
    } catch (err) {
      showToast('❌ Erreur : ' + err.message);
    } finally {
      setDownloading(false);
    }
  };

  /* ── Impression ── */
  const handlePrint = () => {
    const el = document.getElementById('qr-affiche');
    if (!el) return;
    const w = window.open('', '_blank', 'width=900,height=700');
    w.document.write(`<!DOCTYPE html><html><head>
      <meta charset="UTF-8"/>
      <title>Affiche QR — La Maison de la Destinée</title>
      <style>
        @page { size: A4; margin: 20mm; }
        body  { margin:0; display:flex; justify-content:center; align-items:flex-start; }
        @media print { body { -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
      </style></head><body>
      <div style="width:100%;max-width:500px;margin:0 auto">${el.outerHTML}</div>
      </body></html>`);
    w.document.close();
    setTimeout(() => { w.print(); }, 600);
    showToast('🖨️ Impression lancée');
  };

  return (
    <div className="flex flex-col gap-6 max-w-5xl">
      {/* En-tête */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="font-display text-xl font-bold text-gray-800">QR Code d'accueil</h1>
          <p className="text-gray-400 text-sm font-body mt-0.5">
            Affichez ce code sur la table d'accueil ou sur le téléphone de l'accueillant.
          </p>
        </div>
        {/* Lien page publique */}
        <a href={publicUrl} target="_blank" rel="noopener noreferrer"
           className="flex items-center gap-2 px-4 py-2 rounded-xl bg-amber-50 border border-church-gold/40
                      text-church-gold-dk font-body font-semibold text-sm hover:bg-amber-100 transition-all">
          📲 Lien accueillantes →
        </a>
      </div>

      {/* Info page publique */}
      <div className="flex items-start gap-3 px-4 py-3.5 bg-purple-50 border border-purple-100 rounded-xl">
        <span className="text-xl shrink-0">💡</span>
        <div>
          <p className="text-sm font-body font-semibold text-church-purple">Page publique pour les accueillantes</p>
          <p className="text-xs text-gray-500 font-body mt-0.5">
            Partagez ce lien : <strong
              className="text-church-purple select-all cursor-text font-mono"
              onClick={() => { navigator.clipboard?.writeText(publicUrl); showToast('✅ Lien copié !'); }}
              title="Cliquer pour copier"
            >{publicUrl}</strong> — sans connexion requise.
            Après chaque changement de verset, cliquez <strong>"Appliquer"</strong> pour synchroniser.
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">

        {/* ── Aperçu affiche ── */}
        <div className="flex flex-col gap-3">
          <p className="text-sm font-body font-bold text-gray-600">Aperçu — ce que vous téléchargez</p>
          {loading ? (
            <div className="bg-white rounded-3xl h-96 flex items-center justify-center text-gray-400 shadow-sm border border-gray-100">
              <svg className="animate-spin w-6 h-6 mr-2 text-church-purple" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
              <span className="text-sm font-body">Génération…</span>
            </div>
          ) : (
            <QRAffiche id="qr-affiche" qrDataURL={qrDataURL} verset={currentVerset} />
          )}
          {qrUrl && (
            <div className="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-xl border border-gray-200 text-xs font-body">
              <span className="text-gray-400">🔗</span>
              <span className="text-church-purple font-semibold truncate">{qrUrl}</span>
            </div>
          )}
        </div>

        {/* ── Panneau contrôle ── */}
        <div className="flex flex-col gap-4">

          {/* Actions */}
          <div className="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm">
            <p className="text-sm font-body font-bold text-gray-700 mb-3">Actions</p>
            <div className="flex flex-col gap-2">

              {/* Télécharger affiche complète */}
              <button onClick={handleDownloadAffiche} disabled={downloading || loading}
                className="w-full flex items-center gap-3 px-4 py-3 rounded-xl bg-church-purple text-white
                           font-body font-semibold text-sm hover:bg-church-purple-lg transition-all disabled:opacity-50">
                <span className="text-lg shrink-0">{downloading ? '⏳' : '⬇'}</span>
                <div className="text-left">
                  <p>{downloading ? 'Génération…' : 'Télécharger l\'affiche complète'}</p>
                  <p className="text-xs opacity-70 font-normal">Avec entête, QR code et verset — PNG haute résolution</p>
                </div>
              </button>

              {/* Imprimer */}
              <button onClick={handlePrint} disabled={loading}
                className="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-church-purple/30
                           text-church-purple font-body font-semibold text-sm hover:bg-purple-50 transition-all disabled:opacity-50">
                <span className="text-lg shrink-0">🖨️</span>
                <div className="text-left">
                  <p>Imprimer l'affiche</p>
                  <p className="text-xs text-gray-400 font-normal">Format A4 avec toutes les couleurs</p>
                </div>
              </button>

              {/* Mode plein écran */}
              <button onClick={() => setFullscreen(true)}
                className="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-gray-200
                           text-gray-600 font-body font-semibold text-sm hover:border-church-purple/30 transition-all">
                <span className="text-lg shrink-0">⛶</span>
                <div className="text-left">
                  <p>Mode plein écran</p>
                  <p className="text-xs text-gray-400 font-normal">Pour présenter sur tablette ou grand écran</p>
                </div>
              </button>

              {/* Lien accueillantes */}
              <a href={publicUrl} target="_blank" rel="noopener noreferrer"
                 className="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-amber-200
                            text-amber-700 font-body font-semibold text-sm hover:bg-amber-50 transition-all">
                <span className="text-lg shrink-0">📲</span>
                <div className="text-left">
                  <p>Ouvrir la page accueillantes</p>
                  <p className="text-xs text-amber-500 font-normal">Page publique — sans connexion requise</p>
                </div>
              </a>
            </div>
          </div>

          {/* Verset */}
          <div className="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm">
            <p className="text-sm font-body font-bold text-gray-700 mb-3">✝️ Verset biblique</p>
            <div className="flex bg-gray-100 rounded-xl p-1 mb-3">
              {['Prédéfinis', 'Personnalisé'].map((l, i) => (
                <button key={l} onClick={() => setUseCustom(i === 1)}
                  className={`flex-1 py-1.5 rounded-lg text-xs font-body font-medium transition-all
                    ${useCustom === (i===1) ? 'bg-white shadow-sm text-church-purple font-semibold' : 'text-gray-500'}`}>
                  {l}
                </button>
              ))}
            </div>
            {!useCustom ? (
              <div className="flex flex-col gap-2">
                {VERSETS.map((v, i) => (
                  <button key={i} onClick={() => setSelectedVerset(i)}
                    className={`text-left px-3 py-2.5 rounded-xl border-2 transition-all
                      ${selectedVerset === i ? 'border-church-purple bg-purple-50/60' : 'border-gray-200 hover:border-church-purple/30'}`}>
                    <p className={`text-xs font-body font-bold mb-0.5 ${selectedVerset===i?'text-church-purple':'text-gray-600'}`}>{v.ref}</p>
                    <p className="text-xs text-gray-400 font-body italic line-clamp-2">« {v.text.substring(0,80)}… »</p>
                  </button>
                ))}
              </div>
            ) : (
              <div className="flex flex-col gap-2">
                <input type="text" value={customVerset.ref}
                  onChange={e => setCustomVerset(p => ({ ...p, ref: e.target.value }))}
                  placeholder="ex: Philippiens 4:13" className="church-input text-sm" />
                <textarea value={customVerset.text} rows={3}
                  onChange={e => setCustomVerset(p => ({ ...p, text: e.target.value }))}
                  placeholder="Je puis tout par celui qui me fortifie."
                  className="church-input text-sm resize-none" />
              </div>
            )}

            {/* ── Bouton Appliquer ── */}
            <button onClick={handleApplyVerset} disabled={saving}
              className="w-full mt-4 flex items-center justify-center gap-2 px-4 py-3 rounded-xl
                         bg-church-purple text-white font-body font-semibold text-sm
                         hover:bg-church-purple-lg transition-all disabled:opacity-50 shadow-sm">
              {saving ? (
                <>
                  <svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                  </svg>
                  Sauvegarde…
                </>
              ) : <>✅ Appliquer ce verset</>}
            </button>
            <p className="text-xs text-gray-400 font-body text-center mt-1.5">
              Synchronise immédiatement la page des accueillantes
            </p>
          </div>

          {/* Info URL */}
          {isSuperAdmin && (
            <div className="bg-amber-50 border border-amber-200 rounded-xl p-4">
              <p className="text-xs font-body font-bold text-amber-700 mb-1">💡 Changer l'URL encodée</p>
              <p className="text-xs text-amber-600 font-body leading-relaxed">
                Modifiez <code className="bg-amber-100 px-1 rounded">FRONTEND_URL</code> dans votre <code className="bg-amber-100 px-1 rounded">.env</code> backend puis redémarrez.
              </p>
              <p className="text-xs text-amber-500 font-body mt-1">Actuellement : <strong>{qrUrl}</strong></p>
            </div>
          )}
        </div>
      </div>

      {/* ── Mode plein écran ── */}
      {fullscreen && (
        <div className="fixed inset-0 z-50 flex flex-col items-center justify-between"
             style={{ background: 'linear-gradient(160deg, #3D0A5A 0%, #6B1F8A 55%, #4A0E6B 100%)' }}>
          <div className="w-full h-1.5"
               style={{ background: 'linear-gradient(90deg, #9B7A10, #C9A227, #E8C547, #C9A227, #9B7A10)' }} />
          <button onClick={() => setFullscreen(false)}
            className="absolute top-4 right-4 w-10 h-10 rounded-full bg-white/20 text-white
                       flex items-center justify-center hover:bg-white/30 text-xl z-10">✕</button>
          <div className="flex-1 flex flex-col items-center justify-center px-6 py-8 gap-6">
            <div className="text-center">
              <div className="w-16 h-16 rounded-full bg-white/10 flex items-center justify-center mx-auto mb-3 text-3xl">🏛️</div>
              <h1 className="font-display text-2xl font-bold text-white">La Maison de la Destinée</h1>
              <div style={{ height:2, width:80, background:'linear-gradient(90deg,transparent,#C9A227,transparent)', margin:'10px auto' }} />
              <p className="text-white/60 text-sm font-body">Bienvenue parmi nous 🙏</p>
            </div>
            <div>
              <p className="text-white font-body font-bold text-lg text-center mb-4">📱 Scannez pour vous inscrire</p>
              <div style={{ padding:16, background:'#fff', borderRadius:20, boxShadow:'0 16px 60px rgba(0,0,0,0.35)', border:'3px solid rgba(201,162,39,0.3)' }}>
                {qrDataURL && <img src={qrDataURL} alt="QR" style={{ width:240, height:240, display:'block' }} />}
              </div>
            </div>
          </div>
          {currentVerset?.text && (
            <div className="w-full px-8 pb-4 pt-3 text-center" style={{ borderTop:'1px solid rgba(201,162,39,0.2)' }}>
              <p className="text-white/60 text-xs font-body italic">« {currentVerset.text.substring(0,120)}{currentVerset.text.length>120?'…':''} »</p>
              <p className="text-xs font-bold mt-1" style={{ color:'#C9A227' }}>— {currentVerset.ref}</p>
            </div>
          )}
          <div className="w-full h-1.5"
               style={{ background: 'linear-gradient(90deg, #9B7A10, #C9A227, #E8C547, #C9A227, #9B7A10)' }} />
        </div>
      )}

      {toast && (
        <div className="fixed bottom-6 right-6 z-50 bg-gray-900 text-white px-5 py-3
                        rounded-xl shadow-2xl font-body text-sm animate-fade-up max-w-sm">
          {toast}
        </div>
      )}
    </div>
  );
};

export default QRCodePage;