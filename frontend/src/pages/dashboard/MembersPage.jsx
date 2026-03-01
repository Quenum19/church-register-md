import { useEffect, useState, useCallback } from 'react';
import { getVisitors } from '../../api/admin';

const FAMILY_ICONS = {
  Force:'⚡', Honneur:'🏅', Gloire:'✨', Louange:'🎵',
  Puissance:'💪', Richesse:'🌿', Sagesse:'📖',
};

const fmt = (d) => d ? new Date(d).toLocaleDateString('fr-FR', { day:'numeric', month:'short', year:'numeric' }) : '—';

/* Timeline compacte F1→F2→F3 */
const MiniTimeline = ({ visitor }) => {
  const visits = [
    { n:1, v: visitor.visit1, color: 'bg-church-purple', label: '1ère visite' },
    { n:2, v: visitor.visit2, color: 'bg-church-gold',   label: '2ème visite' },
    { n:3, v: visitor.visit3, color: 'bg-green-500',     label: '3ème visite' },
  ].filter(x => x.v);

  return (
    <div className="flex items-center gap-1 flex-wrap mt-2">
      {visits.map(({ n, v, color, label }, i) => (
        <div key={n} className="flex items-center gap-1">
          {i > 0 && <span className="text-gray-300 text-xs">→</span>}
          <div className="flex items-center gap-1.5 bg-gray-50 rounded-full px-2.5 py-1 border border-gray-100">
            <div className={`w-1.5 h-1.5 rounded-full ${color} shrink-0`} />
            <span className="text-xs text-gray-600 font-body">
              {FAMILY_ICONS[v.familleAccueil] || '🏠'} {v.familleAccueil || '—'}
            </span>
            <span className="text-xs text-gray-400 font-body">· {fmt(v.date)}</span>
          </div>
        </div>
      ))}
    </div>
  );
};

const MembersPage = ({ onSelectVisitor }) => {
  const [members, setMembers]   = useState([]);
  const [total,   setTotal]     = useState(0);
  const [pages,   setPages]     = useState(1);
  const [page,    setPage]      = useState(1);
  const [loading, setLoading]   = useState(true);
  const [search,  setSearch]    = useState('');
  const [view,    setView]      = useState('table'); // table | cards

  const load = useCallback(async (p = 1) => {
    setLoading(true);
    try {
      const { data } = await getVisitors({
        page: p, limit: 20, status: 'membre',
        search: search || undefined,
      });
      setMembers(data.data.visitors);
      setTotal(data.data.total);
      setPages(data.data.pages);
    } catch(e) { console.error(e); }
    finally { setLoading(false); }
  }, [search]);

  useEffect(() => { setPage(1); load(1); }, []);
  const handleSearch = (e) => { if (e.key === 'Enter') { setPage(1); load(1); } };

  return (
    <div className="flex flex-col gap-5 max-w-7xl">
      {/* En-tête */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="font-display text-xl font-bold text-gray-800">Membres</h1>
          <p className="text-gray-400 text-sm font-body">{total} membre{total > 1 ? 's' : ''} confirmé{total > 1 ? 's' : ''}</p>
        </div>
        <div className="flex bg-gray-100 rounded-xl p-1">
          {[{v:'table',icon:'☰'},{v:'cards',icon:'⊞'}].map(({v,icon}) => (
            <button key={v} onClick={() => setView(v)}
              className={`px-3 py-1.5 rounded-lg text-sm transition-all font-body
                ${view === v ? 'bg-white shadow-sm text-church-purple font-semibold' : 'text-gray-500'}`}>
              {icon}
            </button>
          ))}
        </div>
      </div>

      {/* Recherche */}
      <div className="relative max-w-sm">
        <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">🔍</span>
        <input type="text" placeholder="Nom, téléphone…"
          value={search} onChange={e => setSearch(e.target.value)} onKeyDown={handleSearch}
          className="church-input pl-9 bg-white" />
      </div>

      {/* Résultats */}
      {loading ? (
        <div className="flex items-center justify-center py-16 text-gray-400">
          <svg className="animate-spin w-6 h-6 mr-2 text-church-purple" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
          <span className="text-sm font-body">Chargement…</span>
        </div>
      ) : members.length === 0 ? (
        <div className="text-center py-16">
          <p className="text-4xl mb-3">🏛️</p>
          <p className="text-gray-400 font-body text-sm">Aucun membre enregistré pour l'instant.</p>
        </div>
      ) : view === 'table' ? (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[700px]">
              <thead className="bg-gradient-to-r from-green-600 to-green-500">
                <tr>
                  {['Membre','Contact','Parcours de visite','Converti le',''].map(h => (
                    <th key={h} className="text-left px-4 py-3 text-xs font-body font-bold text-white/80 uppercase tracking-wide">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {members.map(m => (
                  <tr key={m._id} className="hover:bg-green-50/20 transition-colors">
                    <td className="px-4 py-4">
                      <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-full bg-green-100 flex items-center justify-center
                                        text-green-700 font-bold text-sm shrink-0">
                          {(m.visit1?.fullName || '?')[0]}
                        </div>
                        <div>
                          <p className="font-body font-semibold text-sm text-gray-800">
                            {m.visit1?.fullName || m.visit2?.fullName || '—'}
                          </p>
                          <span className="text-xs bg-green-50 text-green-700 border border-green-200
                                           px-2 py-0.5 rounded-full font-body font-bold">✅ Membre</span>
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-4">
                      <p className="text-sm text-gray-600 font-body">{m.phone}</p>
                      {m.visit1?.city && (
                        <p className="text-xs text-gray-400 font-body">📍 {m.visit1.city}</p>
                      )}
                    </td>
                    <td className="px-4 py-4">
                      <MiniTimeline visitor={m} />
                    </td>
                    <td className="px-4 py-4 text-xs text-gray-400 font-body">
                      {m.convertedToMemberAt ? fmt(m.convertedToMemberAt) : '—'}
                    </td>
                    <td className="px-4 py-4">
                      <button onClick={() => onSelectVisitor(m._id)}
                        className="text-xs text-church-purple font-body font-semibold
                                   hover:text-church-gold-dk transition-colors">
                        Voir →
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          {members.map(m => (
            <div key={m._id} onClick={() => onSelectVisitor(m._id)}
              className="bg-white rounded-2xl p-5 border border-green-100 shadow-sm
                         hover:shadow-md hover:border-green-300 transition-all cursor-pointer">
              <div className="flex items-center gap-3 mb-3">
                <div className="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center
                                text-green-700 font-bold text-sm shrink-0">
                  {(m.visit1?.fullName || '?')[0]}
                </div>
                <div className="flex-1 min-w-0">
                  <p className="font-body font-semibold text-sm text-gray-800 truncate">
                    {m.visit1?.fullName || '—'}
                  </p>
                  <p className="text-xs text-gray-400">{m.phone}</p>
                </div>
                <span className="text-xs bg-green-50 text-green-700 border border-green-200
                                 px-2 py-0.5 rounded-full font-body font-bold shrink-0">✅</span>
              </div>
              <MiniTimeline visitor={m} />
              {m.convertedToMemberAt && (
                <p className="text-xs text-gray-400 font-body mt-2">
                  Membre depuis le {fmt(m.convertedToMemberAt)}
                </p>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Pagination */}
      {pages > 1 && (
        <div className="flex justify-center items-center gap-2">
          <button onClick={() => setPage(p => Math.max(1,p-1))} disabled={page===1}
            className="w-9 h-9 rounded-xl border-2 border-gray-200 text-sm font-bold disabled:opacity-30 hover:border-church-purple/40 transition-all">‹</button>
          {Array.from({length: Math.min(5,pages)}, (_,i) => {
            const p = pages<=5 ? i+1 : page<=3 ? i+1 : page>=pages-2 ? pages-4+i : page-2+i;
            return <button key={p} onClick={() => setPage(p)}
              className={`w-9 h-9 rounded-xl text-sm font-semibold transition-all
                ${p===page ? 'bg-church-purple text-white' : 'border-2 border-gray-200 text-gray-600 hover:border-church-purple/40'}`}>{p}</button>;
          })}
          <button onClick={() => setPage(p => Math.min(pages,p+1))} disabled={page===pages}
            className="w-9 h-9 rounded-xl border-2 border-gray-200 text-sm font-bold disabled:opacity-30 hover:border-church-purple/40 transition-all">›</button>
          <span className="text-xs text-gray-400 font-body ml-1">Page {page}/{pages}</span>
        </div>
      )}
    </div>
  );
};

export default MembersPage;