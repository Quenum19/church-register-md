import { useEffect, useState, useCallback } from 'react';
import { useAuth } from '../../context/AuthContext';
import { getCurrentFamily } from '../../utils/familyService';

const API_BASE    = process.env.REACT_APP_API_URL || 'http://localhost:5000/api';
const authHeaders = () => ({
  'Content-Type': 'application/json',
  Authorization:  `Bearer ${localStorage.getItem('token')}`,
});

// ── Ordre officiel de rotation ─────────────────────────────────────
const FAMILIES_ORDER = ['Puissance','Richesse','Sagesse','Force','Honneur','Gloire','Louange'];

const FAMILY_ICONS = {
  Puissance:'💪', Richesse:'🌿', Sagesse:'📖',
  Force:'⚡', Honneur:'🏅', Gloire:'✨', Louange:'🎵',
};
const FAMILY_COLORS = {
  Puissance:'#f97316', Richesse:'#10b981', Sagesse:'#8b5cf6',
  Force:'#ef4444', Honneur:'#3b82f6', Gloire:'#eab308', Louange:'#ec4899',
};
const MONTHS = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
const ROLE_LABELS = { responsable:'Responsable', pasteur:'Pasteur', autre:'Autre' };

/* ── Mini bar chart ──────────────────────────────────────────────── */
const BarChart = ({ data, color }) => {
  if (!data?.length) return <p className="text-xs text-gray-400 text-center py-4">Pas de données</p>;
  const max = Math.max(...data.map(d => d.value), 1);
  return (
    <div className="flex items-end gap-1.5 h-20">
      {data.map((d, i) => (
        <div key={i} className="flex flex-col items-center gap-0.5 flex-1">
          {d.value > 0 && <span className="text-xs text-gray-500 font-bold leading-none">{d.value}</span>}
          <div className="w-full rounded-t-md transition-all duration-700"
               style={{ height:`${Math.max((d.value/max)*56, d.value>0?3:1)}px`, background: color, opacity:.8 }} />
          <span className="text-xs text-gray-400 font-body">{d.label}</span>
        </div>
      ))}
    </div>
  );
};

/* ── Carte rapport famille ───────────────────────────────────────── */
const ReportCard = ({ report, isCurrent, onSend, isSuperAdmin, sending }) => {
  const icon  = FAMILY_ICONS[report.family]  || '🏠';
  const color = FAMILY_COLORS[report.family] || '#6B1F8A';
  const barData = [
    { label:'F1', value: report.stats?.byVisit?.v1  || 0 },
    { label:'F2', value: report.stats?.byVisit?.v2  || 0 },
    { label:'F3', value: report.stats?.byVisit?.v3  || 0 },
    { label:'Mbr', value: report.stats?.converted   || 0 },
  ];

  return (
    <div className={`bg-white rounded-2xl p-5 border-2 shadow-sm hover:shadow-md transition-all
                     ${isCurrent ? 'border-church-gold/50 ring-2 ring-church-gold/20' : 'border-gray-100'}`}>
      <div className="flex items-start justify-between mb-3">
        <div className="flex items-center gap-2.5">
          <div className="w-10 h-10 rounded-xl flex items-center justify-center text-xl"
               style={{ background: color + '18' }}>{icon}</div>
          <div>
            <div className="flex items-center gap-2">
              <p className="font-display font-bold text-gray-800 text-sm">Famille {report.family}</p>
              {isCurrent && (
                <span className="text-xs bg-church-gold text-church-purple-dk px-2 py-0.5 rounded-full font-body font-bold">
                  En service
                </span>
              )}
            </div>
            <p className="text-xs text-gray-400 font-body">{report.period}</p>
          </div>
        </div>
        {report.emailSentAt
          ? <span className="text-xs bg-green-50 text-green-700 border border-green-200 px-2 py-1 rounded-full font-body font-semibold shrink-0">✓ Envoyé</span>
          : <span className="text-xs bg-gray-50 text-gray-400 border border-gray-200 px-2 py-1 rounded-full font-body shrink-0">Non envoyé</span>}
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-4 gap-1.5 mb-3">
        {[
          { label:'Total',   value: report.stats?.totalVisits||0, color:'#6B1F8A' },
          { label:'Nv F1',   value: report.stats?.byVisit?.v1||0, color:'#3b82f6' },
          { label:'Retours', value: report.stats?.returning||0,   color:'#f97316' },
          { label:'Membres', value: report.stats?.converted||0,   color:'#16a34a' },
        ].map(k => (
          <div key={k.label} className="bg-gray-50 rounded-xl p-2 text-center">
            <p className="font-display text-lg font-bold leading-none" style={{ color: k.color }}>{k.value}</p>
            <p className="text-xs text-gray-400 font-body mt-0.5">{k.label}</p>
          </div>
        ))}
      </div>

      <BarChart data={barData} color={color} />

      <div className="mt-3 pt-3 border-t border-gray-50 flex items-center justify-between">
        <p className="text-xs text-gray-400 font-body">
          {report.visitors?.length || 0} visiteur{(report.visitors?.length||0)>1?'s':''} listés
        </p>
        {isSuperAdmin && (
          <button onClick={() => onSend(report._id)} disabled={sending === report._id}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-body font-semibold
                       bg-church-purple text-white hover:bg-church-purple-lg transition-all disabled:opacity-50">
            {sending === report._id
              ? <><svg className="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg> Envoi…</>
              : '📧 Envoyer'}
          </button>
        )}
      </div>
    </div>
  );
};

/* ── Config modal famille ────────────────────────────────────────── */
const FamilyConfigModal = ({ family, config, onClose, onSaved }) => {
  const [recipients,   setRecipients]   = useState(
    config?.recipients?.length ? config.recipients : [{ name:'', email:'', role:'responsable' }]
  );
  const [autoEnabled, setAutoEnabled]   = useState(config?.autoReportEnabled ?? true);
  const [saving,      setSaving]        = useState(false);
  const [error,       setError]         = useState('');

  const icon  = FAMILY_ICONS[family]  || '🏠';
  const color = FAMILY_COLORS[family] || '#6B1F8A';

  const add    = () => recipients.length < 5 && setRecipients(p => [...p, { name:'', email:'', role:'responsable' }]);
  const remove = (i) => setRecipients(p => p.filter((_,idx) => idx !== i));
  const update = (i, f, v) => setRecipients(p => p.map((r,idx) => idx === i ? { ...r, [f]: v } : r));

  const handleSave = async () => {
    setError('');
    const valid = recipients.filter(r => r.name.trim() && r.email.trim());
    if (!valid.length) { setError('Ajoutez au moins un destinataire valide (nom + email).'); return; }
    setSaving(true);
    try {
      const res = await fetch(`${API_BASE}/reports/family-configs/${family}`, {
        method:'PUT', headers: authHeaders(),
        body: JSON.stringify({ recipients: valid, autoReportEnabled: autoEnabled }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message);
      onSaved();
    } catch(err) { setError(err.message || 'Erreur de sauvegarde.'); }
    finally { setSaving(false); }
  };

  return (
    <div className="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-2xl p-7 max-w-lg w-full shadow-2xl max-h-[90vh] overflow-y-auto">

        {/* En-tête */}
        <div className="flex items-center justify-between mb-5">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl text-xl flex items-center justify-center"
                 style={{ background: color+'18' }}>{icon}</div>
            <div>
              <h3 className="font-display text-lg font-bold text-gray-800">Famille {family}</h3>
              <p className="text-xs text-gray-400 font-body">Configuration des destinataires du rapport</p>
            </div>
          </div>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
        </div>

        {/* Explication */}
        <div className="mb-5 p-4 bg-purple-50 rounded-xl border border-purple-100">
          <p className="text-sm font-body text-church-purple font-semibold mb-1">📧 Comment ça fonctionne ?</p>
          <p className="text-xs text-gray-600 font-body leading-relaxed">
            Les personnes listées ici recevront automatiquement par email le rapport mensuel de la famille <strong>{family}</strong> le 1er de chaque mois.
            Vous pouvez aussi l'envoyer manuellement depuis l'onglet Rapports.
          </p>
        </div>

        {/* Toggle auto */}
        <button type="button" onClick={() => setAutoEnabled(p => !p)}
          className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 mb-5 transition-all
            ${autoEnabled ? 'border-church-purple/40 bg-purple-50/60' : 'border-gray-200 bg-white'}`}>
          <div className={`w-11 h-6 rounded-full transition-all shrink-0 relative ${autoEnabled ? 'bg-church-purple' : 'bg-gray-300'}`}>
            <div className={`absolute top-1 w-4 h-4 rounded-full bg-white shadow transition-all ${autoEnabled ? 'left-6' : 'left-1'}`} />
          </div>
          <div className="text-left">
            <p className="font-body font-semibold text-sm text-gray-700">Rapport automatique</p>
            <p className="text-xs text-gray-400 font-body">Envoi le 1er de chaque mois à 08h00</p>
          </div>
        </button>

        {/* Destinataires */}
        <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-3">
          Destinataires — {recipients.length}/5
        </p>
        <div className="flex flex-col gap-3 mb-4">
          {recipients.map((r, i) => (
            <div key={i} className="bg-gray-50 rounded-xl p-3 border border-gray-100">
              <div className="flex items-center justify-between mb-2">
                <p className="text-xs font-body font-bold text-gray-500">Destinataire {i+1}</p>
                {recipients.length > 1 && (
                  <button onClick={() => remove(i)}
                    className="text-xs text-red-400 hover:text-red-600 font-body font-semibold transition-colors">
                    Supprimer
                  </button>
                )}
              </div>
              <div className="flex gap-2 mb-2">
                <input type="text" placeholder="Nom complet" value={r.name}
                  onChange={e => update(i,'name',e.target.value)}
                  className="church-input flex-1 text-sm bg-white" />
                <select value={r.role} onChange={e => update(i,'role',e.target.value)}
                  className="church-input w-auto text-sm cursor-pointer bg-white">
                  {Object.entries(ROLE_LABELS).map(([v,l]) => <option key={v} value={v}>{l}</option>)}
                </select>
              </div>
              <input type="email" placeholder="adresse@email.com" value={r.email}
                onChange={e => update(i,'email',e.target.value)}
                className="church-input text-sm w-full bg-white" />
            </div>
          ))}
        </div>

        {recipients.length < 5 && (
          <button onClick={add}
            className="flex items-center gap-1.5 text-sm text-church-purple font-body font-semibold
                       hover:text-church-gold-dk transition-colors mb-5">
            + Ajouter un destinataire
          </button>
        )}

        {error && (
          <div className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm font-body mb-3">
            ✕ {error}
          </div>
        )}

        <div className="flex gap-3">
          <button onClick={onClose}
            className="flex-1 px-4 py-2.5 rounded-xl border-2 border-gray-200 text-gray-600 font-body font-semibold text-sm hover:border-church-purple/40 transition-all">
            Annuler
          </button>
          <button onClick={handleSave} disabled={saving} className="flex-1 btn-gold text-sm">
            {saving ? 'Sauvegarde…' : 'Enregistrer'}
          </button>
        </div>
      </div>
    </div>
  );
};

/* ── Composant principal ─────────────────────────────────────────── */
const ReportsPage = () => {
  const { isSuperAdmin } = useAuth();
  const currentFamily    = getCurrentFamily();
  const now              = new Date();

  // Par défaut : mois précédent (pour voir les données du mois écoulé)
  const prevMonth = now.getMonth() === 0 ? 12 : now.getMonth();
  const prevYear  = now.getMonth() === 0 ? now.getFullYear()-1 : now.getFullYear();

  const [selectedMonth, setSelectedMonth] = useState(prevMonth);
  const [selectedYear,  setSelectedYear]  = useState(prevYear);
  const [reports,       setReports]       = useState([]);
  const [configs,       setConfigs]       = useState([]);
  const [loading,       setLoading]       = useState(true);
  const [generating,    setGenerating]    = useState(false);
  const [sending,       setSending]       = useState(null);
  const [testingEmail,  setTestingEmail]  = useState(false);
  const [configModal,   setConfigModal]   = useState(null);
  const [activeTab,     setActiveTab]     = useState('reports');
  const [toast,         setToast]         = useState('');

  const showToast = (msg, dur=4000) => { setToast(msg); setTimeout(()=>setToast(''), dur); };

  const loadReports = useCallback(async () => {
    setLoading(true);
    try {
      const res  = await fetch(`${API_BASE}/reports?month=${selectedMonth}&year=${selectedYear}`, { headers: authHeaders() });
      const data = await res.json();
      setReports(data.data?.reports || []);
    } catch(e) { console.error(e); }
    finally { setLoading(false); }
  }, [selectedMonth, selectedYear]);

  const loadConfigs = useCallback(async () => {
    try {
      const res  = await fetch(`${API_BASE}/reports/family-configs`, { headers: authHeaders() });
      const data = await res.json();
      setConfigs(data.data?.configs || []);
    } catch(e) { console.error(e); }
  }, []);

  useEffect(() => { loadReports(); }, [loadReports]);
  useEffect(() => { loadConfigs(); }, [loadConfigs]);

  const handleGenerate = async () => {
    setGenerating(true);
    try {
      const res  = await fetch(`${API_BASE}/reports/generate`, {
        method:'POST', headers: authHeaders(),
        body: JSON.stringify({ month: selectedMonth, year: selectedYear }),
      });
      const data = await res.json();
      if (data.status === 'success') { showToast(`✅ ${data.message}`); loadReports(); }
      else showToast(`❌ ${data.message}`);
    } catch(e) { showToast('❌ Erreur de connexion au serveur.'); }
    finally { setGenerating(false); }
  };

  const handleSend = async (reportId) => {
    setSending(reportId);
    try {
      const res  = await fetch(`${API_BASE}/reports/send`, {
        method:'POST', headers: authHeaders(),
        body: JSON.stringify({ reportId }),
      });
      const data = await res.json();
      showToast(data.status === 'success' ? `✅ ${data.message}` : `❌ ${data.message}`);
      if (data.status === 'success') loadReports();
    } catch(e) { showToast('❌ Erreur lors de l\'envoi.'); }
    finally { setSending(null); }
  };

  const handleTestEmail = async () => {
    setTestingEmail(true);
    try {
      const res  = await fetch(`${API_BASE}/reports/test-email`, {
        method:'POST', headers: authHeaders(),
        body: JSON.stringify({ email:'sioromualdquenum@gmail.com' }),
      });
      const data = await res.json();
      showToast(data.status==='success' ? `✅ ${data.message}` : `❌ ${data.message}`);
    } catch(e) { showToast('❌ Erreur email.'); }
    finally { setTestingEmail(false); }
  };

  const years     = Array.from({ length: 5 }, (_, i) => 2026 + i);
  const getConfig = (family) => configs.find(c => c.family === family) || { family, recipients:[], autoReportEnabled:true };

  // Trier les rapports dans l'ordre officiel
  const sortedReports = FAMILIES_ORDER.map(family =>
    reports.find(r => r.family === family)
  ).filter(Boolean);

  // Familles sans rapport pour ce mois
  const missingFamilies = FAMILIES_ORDER.filter(f => !reports.find(r => r.family === f));

  return (
    <div className="flex flex-col gap-6 max-w-6xl">
      {/* En-tête */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="font-display text-xl font-bold text-gray-800">Rapports mensuels</h1>
          <p className="text-gray-400 text-sm font-body">
            Un rapport par famille · Cron automatique le 1er du mois à 08h00
          </p>
        </div>
        {/* {isSuperAdmin && (
          <button onClick={handleTestEmail} disabled={testingEmail}
            className="px-4 py-2 rounded-xl border-2 border-gray-200 text-xs text-gray-500 font-body
                       font-semibold hover:border-church-purple/40 transition-all">
            {testingEmail ? '📧 Envoi…' : '🧪 Tester email'}
          </button>
        )} */}
      </div>

      {/* Onglets */}
      <div className="flex bg-gray-100 rounded-xl p-1 w-fit">
        {[{k:'reports',l:'📊 Rapports'},{k:'config',l:'⚙️ Configuration'}].map(({k,l}) => (
          <button key={k} onClick={() => setActiveTab(k)}
            className={`px-4 py-2 rounded-lg text-sm transition-all font-body font-medium
              ${activeTab===k ? 'bg-white shadow-sm text-church-purple font-semibold' : 'text-gray-500 hover:text-gray-700'}`}>
            {l}
          </button>
        ))}
      </div>

      {/* ── Onglet Rapports ── */}
      {activeTab === 'reports' && (<>
        <div className="flex flex-wrap items-center gap-3">
          <select value={selectedMonth} onChange={e => setSelectedMonth(Number(e.target.value))}
            className="church-input w-auto bg-white cursor-pointer">
            {MONTHS.map((m,i) => <option key={i+1} value={i+1}>{m}</option>)}
          </select>
          <select value={selectedYear} onChange={e => setSelectedYear(Number(e.target.value))}
            className="church-input w-auto bg-white cursor-pointer">
            {years.map(y => <option key={y} value={y}>{y}</option>)}
          </select>
          {isSuperAdmin && (
            <button onClick={handleGenerate} disabled={generating}
              className="btn-gold text-sm px-5 py-2.5 flex items-center gap-2">
              {generating
                ? <><svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg> Génération…</>
                : '⚡ Générer les rapports'}
            </button>
          )}
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-16 text-gray-400">
            <svg className="animate-spin w-6 h-6 mr-2 text-church-purple" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <span className="text-sm font-body">Chargement…</span>
          </div>
        ) : sortedReports.length === 0 ? (
          <div className="text-center py-16 bg-white rounded-2xl border border-gray-100">
            <p className="text-4xl mb-3">📊</p>
            <p className="text-sm text-gray-500 font-body mb-1">
              Aucun rapport pour {MONTHS[selectedMonth-1]} {selectedYear}.
            </p>
            {isSuperAdmin && (
              <p className="text-xs text-gray-400 font-body">
                Cliquez <strong>"Générer les rapports"</strong> pour calculer les stats de ce mois.
              </p>
            )}
          </div>
        ) : (<>
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            {sortedReports.map(r => (
              <ReportCard key={r._id} report={r}
                isCurrent={r.family === currentFamily}
                onSend={handleSend} isSuperAdmin={isSuperAdmin} sending={sending} />
            ))}
          </div>
          {/* Familles sans rapport */}
          {missingFamilies.length > 0 && isSuperAdmin && (
            <div className="bg-amber-50 border border-amber-200 rounded-xl p-4">
              <p className="text-sm font-body text-amber-700">
                <strong>⚠️ Familles sans rapport pour ce mois :</strong>{' '}
                {missingFamilies.join(', ')}. Cliquez "Générer" pour les créer.
              </p>
            </div>
          )}
        </>)}
      </>)}

      {/* ── Onglet Configuration ── */}
      {activeTab === 'config' && (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-gray-500 font-body bg-blue-50 border border-blue-100 rounded-xl p-3">
            💡 Configurez ici les personnes qui recevront le rapport mensuel de chaque famille par email.
            Famille actuelle en service ce mois : <strong className="text-church-purple">{currentFamily}</strong>
          </p>
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            {FAMILIES_ORDER.map((family, idx) => {
              const cfg      = getConfig(family);
              const isCurr   = family === currentFamily;
              const icon     = FAMILY_ICONS[family];
              const color    = FAMILY_COLORS[family];
              // Numéro dans la rotation (1-7)
              const rotNum   = idx + 1;
              return (
                <div key={family}
                  className={`bg-white rounded-2xl p-5 border-2 shadow-sm transition-all
                    ${isCurr ? 'border-church-gold/50 ring-2 ring-church-gold/20' : 'border-gray-100'}`}>
                  <div className="flex items-start justify-between mb-3">
                    <div className="flex items-center gap-2">
                      <div className="w-9 h-9 rounded-xl text-lg flex items-center justify-center shrink-0"
                           style={{ background: color+'18' }}>{icon}</div>
                      <div>
                        <div className="flex items-center gap-1.5">
                          <p className="font-display font-bold text-sm text-gray-800">Famille {family}</p>
                          {isCurr && (
                            <span className="text-xs bg-church-gold text-church-purple-dk px-1.5 py-0.5 rounded-full font-body font-bold">
                              Ce mois
                            </span>
                          )}
                        </div>
                        <p className="text-xs text-gray-400 font-body">Position {rotNum}/7 dans la rotation</p>
                      </div>
                    </div>
                    <div className={`w-2.5 h-2.5 rounded-full shrink-0 mt-1.5
                                     ${cfg.autoReportEnabled!==false ? 'bg-green-400' : 'bg-gray-300'}`}
                         title={cfg.autoReportEnabled!==false ? 'Auto activé' : 'Auto désactivé'} />
                  </div>

                  {cfg.recipients?.length > 0 ? (
                    <div className="flex flex-col gap-1 mb-3">
                      {cfg.recipients.map((r, i) => (
                        <div key={i} className="flex items-center gap-2 text-xs font-body bg-gray-50 rounded-lg px-2.5 py-1.5">
                          <span className="text-gray-400">{r.role==='pasteur'?'🙏':r.role==='responsable'?'👤':'•'}</span>
                          <span className="font-medium text-gray-700 flex-1 truncate">{r.name}</span>
                          <span className="text-xs bg-white border border-gray-200 text-gray-500 px-1.5 py-0.5 rounded-full">{ROLE_LABELS[r.role]}</span>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <p className="text-xs text-orange-500 font-body mb-3 flex items-center gap-1">
                      <span>⚠️</span> Aucun destinataire — rapport non envoyé
                    </p>
                  )}

                  {isSuperAdmin && (
                    <button onClick={() => setConfigModal(family)}
                      className="w-full px-3 py-2 rounded-xl border-2 border-gray-200 text-xs font-body font-semibold
                                 text-gray-600 hover:border-church-purple/40 hover:text-church-purple transition-all">
                      ✏️ Configurer les destinataires
                    </button>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Modal config */}
      {configModal && (
        <FamilyConfigModal family={configModal} config={getConfig(configModal)}
          onClose={() => setConfigModal(null)}
          onSaved={() => { setConfigModal(null); loadConfigs(); showToast('✅ Configuration sauvegardée !'); }} />
      )}

      {/* Toast */}
      {toast && (
        <div className="fixed bottom-6 right-6 z-50 bg-gray-900 text-white px-5 py-3 rounded-xl
                        shadow-2xl font-body text-sm animate-fade-up max-w-sm">
          {toast}
        </div>
      )}
    </div>
  );
};

export default ReportsPage;