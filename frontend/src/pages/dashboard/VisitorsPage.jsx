import { useEffect, useState, useCallback } from 'react';
import { getVisitors, deleteVisitor, convertToMember } from '../../api/admin';
import { useAuth } from '../../context/AuthContext';

import { exportCSV, exportExcel, exportPDF } from '../../utils/exportHelpers';
import Pagination from '../../components/ui/Pagination';


/* ── Constantes ─────────────────────────────────────────────────── */
const FAMILIES = ['Force','Honneur','Gloire','Louange','Puissance','Richesse','Sagesse'];
const STATUSES = [
  { value: 'prospect',         label: 'Prospect',          color: 'bg-blue-50 text-blue-700 border-blue-200' },
  { value: 'recurrent',        label: 'Récurrent',         color: 'bg-orange-50 text-orange-700 border-orange-200' },
  { value: 'membre_potentiel', label: 'Membre potentiel',  color: 'bg-purple-50 text-church-purple border-purple-200' },
];

const StatusBadge = ({ status }) => {
  const all = [...STATUSES, { value:'membre', label:'Membre', color:'bg-green-50 text-green-700 border-green-200' }];
  const s = all.find(x => x.value === status);
  return s ? <span className={`text-xs font-body font-bold px-2.5 py-1 rounded-full border ${s.color}`}>{s.label}</span> : null;
};

const VisitDots = ({ count }) => (
  <div className="flex gap-1">
    {[1,2,3].map(n => (
      <div key={n} className={`w-2 h-2 rounded-full ${n <= count ? 'bg-church-purple' : 'bg-gray-200'}`} />
    ))}
  </div>
);

/* ── Modal confirmation ─────────────────────────────────────────── */
const ConfirmModal = ({ type, visitor, onConfirm, onCancel }) => {
  const name = visitor?.visit1?.fullName || visitor?.visit2?.fullName || visitor?.phone;
  const isConvert = type === 'convert';

  return (
    <div className="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-2xl p-7 max-w-sm w-full shadow-2xl animate-fade-up">
        <div className={`w-16 h-16 rounded-full mx-auto flex items-center justify-center text-3xl mb-4
                        ${isConvert ? 'bg-green-50' : 'bg-red-50'}`}>
          {isConvert ? '✅' : '🗑️'}
        </div>
        <h3 className="font-display text-lg font-bold text-gray-800 text-center mb-2">
          {isConvert ? 'Convertir en membre' : 'Supprimer la fiche'}
        </h3>
        <p className="text-sm text-gray-500 font-body text-center mb-1">
          {isConvert
            ? <>Confirmer la conversion de <strong className="text-gray-700">{name}</strong> en membre officiel de la Maison de la Destinée ?</>
            : <>Supprimer définitivement la fiche de <strong className="text-gray-700">{name}</strong> ?</>}
        </p>
        {!isConvert && (
          <p className="text-xs text-red-400 font-body text-center mb-4">⚠️ Cette action est irréversible.</p>
        )}
        <div className="flex gap-3 mt-5">
          <button onClick={onCancel}
            className="flex-1 px-4 py-2.5 rounded-xl border-2 border-gray-200 text-gray-600
                       font-body font-semibold text-sm hover:border-gray-300 transition-all">
            Annuler
          </button>
          <button onClick={onConfirm}
            className={`flex-1 px-4 py-2.5 rounded-xl text-white font-body font-semibold text-sm transition-all
              ${isConvert ? 'bg-green-500 hover:bg-green-600' : 'bg-red-500 hover:bg-red-600'}`}>
            {isConvert ? 'Oui, convertir' : 'Oui, supprimer'}
          </button>
        </div>
      </div>
    </div>
  );
};

/* ── Vue tableau ─────────────────────────────────────────────────── */
const TableView = ({ visitors, onSelect, onConvert, onDelete, canEdit, canDelete }) => (
  <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
    <div className="overflow-x-auto">
      <table className="w-full min-w-[750px]">
        <thead className="bg-gradient-to-r from-church-purple to-church-purple-lg">
          <tr>
            {['Visiteur','Localisation','Progression','Familles d\'accueil','Inscription','Actions'].map(h => (
              <th key={h} className="text-left px-4 py-3 text-xs font-body font-bold text-white/80 uppercase tracking-wide">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-50">
          {visitors.map(v => {
            const canConvert = canEdit && v.status !== 'membre' && v.visitCount >= 3;
            return (
              <tr key={v._id} className="hover:bg-purple-50/20 transition-colors group">
                <td className="px-4 py-3">
                  <div className="flex items-center gap-3">
                    <div className="w-8 h-8 rounded-full bg-church-purple/10 flex items-center justify-center
                                    text-church-purple font-bold text-xs shrink-0">
                      {(v.visit1?.fullName || v.visit2?.fullName || '?')[0]}
                    </div>
                    <div>
                      <p className="font-body font-semibold text-sm text-gray-800">
                        {v.visit1?.fullName || v.visit2?.fullName || '—'}
                      </p>
                      <p className="text-xs text-gray-400 font-body">{v.phone}</p>
                    </div>
                  </div>
                </td>
                <td className="px-4 py-3">
                  <p className="text-sm text-gray-600 font-body">{v.visit1?.city || '—'}</p>
                  <p className="text-xs text-gray-400 font-body">{v.visit1?.neighborhood || ''}</p>
                </td>
                <td className="px-4 py-3">
                  <div className="flex flex-col gap-1.5">
                    <VisitDots count={v.visitCount} />
                    <StatusBadge status={v.status} />
                  </div>
                </td>
                <td className="px-4 py-3">
                  <div className="flex gap-1 flex-wrap">
                    {[v.visit1?.familleAccueil, v.visit2?.familleAccueil, v.visit3?.familleAccueil]
                      .filter(Boolean).map((f, i) => (
                        <span key={i} className="text-xs bg-purple-50 text-church-purple px-2 py-0.5 rounded-full font-body border border-purple-100">
                          {f}
                        </span>
                      ))}
                  </div>
                </td>
                <td className="px-4 py-3 text-xs text-gray-400 font-body">
                  {new Date(v.createdAt).toLocaleDateString('fr-FR')}
                </td>
                {/* Actions fixes avec icônes */}
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1">
                    {/* Voir la fiche */}
                    <button onClick={() => onSelect(v._id)} title="Voir la fiche"
                      className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400
                                 hover:bg-church-purple/10 hover:text-church-purple transition-all">
                      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                        <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                      </svg>
                    </button>
                    {/* Convertir — seulement si 3 visites */}
                    {canConvert && (
                      <button onClick={() => onConvert(v)} title="Convertir en membre"
                        className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400
                                   hover:bg-green-50 hover:text-green-600 transition-all">
                        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                          <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                        </svg>
                      </button>
                    )}
                    {/* Supprimer */}
                    {canDelete && (
                      <button onClick={() => onDelete(v)} title="Supprimer"
                        className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400
                                   hover:bg-red-50 hover:text-red-500 transition-all">
                        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                          <path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  </div>
);

/* ── Vue cartes ──────────────────────────────────────────────────── */
const CardView = ({ visitors, onSelect, onConvert, onDelete, canEdit, canDelete }) => (
  <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
    {visitors.map(v => {
      const canConvert = canEdit && v.status !== 'membre' && v.visitCount >= 3;
      return (
        <div key={v._id}
          className="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm
                     hover:shadow-md hover:border-church-purple/20 transition-all cursor-pointer group"
          onClick={() => onSelect(v._id)}>
          <div className="flex items-start justify-between mb-3">
            <div className="w-10 h-10 rounded-full bg-church-purple/10 flex items-center justify-center
                            text-church-purple font-bold text-sm shrink-0">
              {(v.visit1?.fullName || v.visit2?.fullName || '?')[0]}
            </div>
            <div className="flex gap-1.5 flex-wrap justify-end">
              <VisitDots count={v.visitCount} />
            </div>
          </div>
          <p className="font-body font-semibold text-gray-800 text-sm">
            {v.visit1?.fullName || v.visit2?.fullName || '—'}
          </p>
          <p className="text-xs text-gray-400 font-body mt-0.5">{v.phone}</p>
          {v.visit1?.city && (
            <p className="text-xs text-gray-500 font-body mt-1.5">
              📍 {[v.visit1?.neighborhood, v.visit1?.city].filter(Boolean).join(', ')}
            </p>
          )}
          <div className="flex items-center justify-between mt-3 pt-3 border-t border-gray-50">
            <StatusBadge status={v.status} />
            <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
              {canConvert && (
                <button onClick={e => { e.stopPropagation(); onConvert(v); }}
                  title="Convertir"
                  className="w-7 h-7 rounded-lg bg-green-50 text-green-600 flex items-center justify-center text-sm hover:bg-green-100 transition-all">
                  ✅
                </button>
              )}
              {canDelete && (
                <button onClick={e => { e.stopPropagation(); onDelete(v); }}
                  title="Supprimer"
                  className="w-7 h-7 rounded-lg bg-red-50 text-red-400 flex items-center justify-center text-sm hover:bg-red-100 transition-all">
                  🗑️
                </button>
              )}
            </div>
          </div>
        </div>
      );
    })}
  </div>
);

/* ── Composant principal ─────────────────────────────────────────── */
const VisitorsPage = ({ onSelectVisitor }) => {
  const { canEdit, canDelete } = useAuth();
  const [visitors, setVisitors] = useState([]);
  const [total,    setTotal]    = useState(0);
  const [pages,    setPages]    = useState(1);
  const [page,     setPage]     = useState(1);
  const [loading,  setLoading]  = useState(true);
  const [view,     setView]     = useState('table');

  const [search,  setSearch]  = useState('');
  const [status,  setStatus]  = useState('');
  const [famille, setFamille] = useState('');
  const [period,  setPeriod]  = useState('');

  // Modal state
  const [modal, setModal] = useState(null); // { type: 'convert'|'delete', visitor }

  const load = useCallback(async (p = 1) => {
    setLoading(true);
    try {
      const params = { page: p, limit: 20, status: status || undefined,
                       search: search || undefined, famille: famille || undefined,
                       period: period || undefined };
      // Visiteurs seulement (pas membres) sauf si filtre explicite
      if (!status) params.statusExclude = 'membre';
      const { data } = await getVisitors(params);
      setVisitors(data.data.visitors);
      setTotal(data.data.total);
      setPages(data.data.pages);
    } catch (err) { console.error(err); }
    finally { setLoading(false); }
  }, [search, status, famille, period]);

  useEffect(() => { setPage(1); load(1); }, [status, famille, period]);
  useEffect(() => { load(page); }, [page]);

  const handleSearch = (e) => { if (e.key === 'Enter') { setPage(1); load(1); } };

  const handleExport = async (format) => {
    setLoading(true);
    try {
      const { data } = await getVisitors({ page: 1, limit: 2000 });
      const all = data.data.visitors;
      if (format === 'csv')   exportCSV(all);
      if (format === 'excel') await exportExcel(all);
      if (format === 'pdf')   await exportPDF(all);
    } finally { setLoading(false); }
  };

  const handleConvertConfirm = async () => {
    await convertToMember(modal.visitor._id);
    setModal(null);
    load(page);
  };

  const handleDeleteConfirm = async () => {
    await deleteVisitor(modal.visitor._id);
    setModal(null);
    load(page);
  };

  return (
    <div className="flex flex-col gap-5 max-w-7xl">

      {/* En-tête */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="font-display text-xl font-bold text-gray-800">Visiteurs en progression</h1>
          <p className="text-gray-400 text-sm font-body">{total} fiche{total > 1 ? 's' : ''}</p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          {/* Toggle vue */}
          <div className="flex bg-gray-100 rounded-xl p-1">
            {[{v:'table',icon:'☰'},{v:'cards',icon:'⊞'}].map(({v,icon}) => (
              <button key={v} onClick={() => setView(v)}
                className={`px-3 py-1.5 rounded-lg text-sm transition-all font-body
                  ${view === v ? 'bg-white shadow-sm text-church-purple font-semibold' : 'text-gray-500'}`}>
                {icon}
              </button>
            ))}
          </div>
          {/* Export dropdown */}
          <div className="relative group">
            <button className="flex items-center gap-2 px-4 py-2 rounded-xl border-2 border-church-gold/40
                               text-church-gold-dk font-body font-semibold text-sm hover:bg-amber-50 transition-all">
              ⬇ Exporter ▾
            </button>
            <div className="absolute right-0 top-full mt-1 bg-white rounded-xl shadow-lg border border-gray-100
                            min-w-[150px] z-20 opacity-0 group-hover:opacity-100 pointer-events-none
                            group-hover:pointer-events-auto transition-all">
              {[
                { fmt: 'csv',   icon: '📄', label: 'CSV' },
                { fmt: 'excel', icon: '📊', label: 'Excel (.xlsx)' },
                { fmt: 'pdf',   icon: '📋', label: 'PDF (impression)' },
              ].map(({ fmt, icon, label }) => (
                <button key={fmt} onClick={() => handleExport(fmt)}
                  className="w-full flex items-center gap-2 px-4 py-2.5 text-sm font-body text-gray-700
                             hover:bg-purple-50 transition-colors text-left">
                  {icon} {label}
                </button>
              ))}
            </div>
          </div>
        </div>
      </div>

      {/* Filtres */}
      <div className="flex flex-wrap gap-3">
        <div className="flex-1 min-w-48 relative">
          <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">🔍</span>
          <input type="text" placeholder="Nom, téléphone, commune…"
            value={search} onChange={e => setSearch(e.target.value)} onKeyDown={handleSearch}
            className="church-input pl-9 bg-white" />
        </div>
        <select value={status} onChange={e => { setStatus(e.target.value); setPage(1); }}
          className="church-input w-auto bg-white cursor-pointer">
          <option value="">Tous (sauf membres)</option>
          {STATUSES.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
          <option value="membre">Membres</option>
        </select>
        <select value={famille} onChange={e => { setFamille(e.target.value); setPage(1); }}
          className="church-input w-auto bg-white cursor-pointer">
          <option value="">Toutes les familles</option>
          {FAMILIES.map(f => <option key={f} value={f}>{f}</option>)}
        </select>
        <select value={period} onChange={e => { setPeriod(e.target.value); setPage(1); }}
          className="church-input w-auto bg-white cursor-pointer">
          <option value="">Toutes les périodes</option>
          <option value="month">Ce mois-ci</option>
          <option value="year">Cette année</option>
        </select>
        {(search || status || famille || period) && (
          <button onClick={() => { setSearch(''); setStatus(''); setFamille(''); setPeriod(''); setPage(1); }}
            className="px-3 py-2 rounded-xl text-sm text-gray-500 hover:text-church-purple font-body
                       border border-gray-200 hover:border-church-purple/40 transition-all">
            ✕ Réinitialiser
          </button>
        )}
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
      ) : visitors.length === 0 ? (
        <div className="text-center py-16">
          <p className="text-4xl mb-3">🔍</p>
          <p className="text-gray-400 font-body text-sm">Aucun visiteur trouvé avec ces critères.</p>
        </div>
      ) : view === 'table' ? (
        <TableView visitors={visitors} onSelect={onSelectVisitor}
          onConvert={v => setModal({ type: 'convert', visitor: v })}
          onDelete={v => setModal({ type: 'delete', visitor: v })}
          canEdit={canEdit} canDelete={canDelete} />
      ) : (
        <CardView visitors={visitors} onSelect={onSelectVisitor}
          onConvert={v => setModal({ type: 'convert', visitor: v })}
          onDelete={v => setModal({ type: 'delete', visitor: v })}
          canEdit={canEdit} canDelete={canDelete} />
      )}

      {/* Pagination */}
      <Pagination page={page} pages={pages} total={total} onPageChange={setPage} />

      {/* Modal confirmation */}
      {modal && (
        <ConfirmModal
          type={modal.type}
          visitor={modal.visitor}
          onConfirm={modal.type === 'convert' ? handleConvertConfirm : handleDeleteConfirm}
          onCancel={() => setModal(null)}
        />
      )}
    </div>
  );
};

export default VisitorsPage;