import { useEffect, useState } from 'react';
import { getStats } from '../../api/admin';
import { useAuth } from '../../context/AuthContext';
import { getCurrentFamily, getUpcomingSchedule } from '../../utils/familyService';

const FAMILY_ICONS = {
  Force: '⚡', Honneur: '🏅', Gloire: '✨', Louange: '🎵',
  Puissance: '💪', Richesse: '🌿', Sagesse: '📖',
};
const FAMILY_COLORS = {
  Force:     'bg-red-50 text-red-700 border-red-200',
  Honneur:   'bg-blue-50 text-blue-700 border-blue-200',
  Gloire:    'bg-yellow-50 text-yellow-700 border-yellow-200',
  Louange:   'bg-pink-50 text-pink-700 border-pink-200',
  Puissance: 'bg-orange-50 text-orange-700 border-orange-200',
  Richesse:  'bg-emerald-50 text-emerald-700 border-emerald-200',
  Sagesse:   'bg-purple-50 text-purple-700 border-purple-200',
};
const STATUS_CONFIG = {
  prospect:         { label: 'Prospects',         color: 'text-blue-600',   bg: 'bg-blue-500',   icon: '👤' },
  recurrent:        { label: 'Récurrents',         color: 'text-orange-600', bg: 'bg-orange-500', icon: '🔄' },
  membre_potentiel: { label: 'Membres potentiels', color: 'text-purple-600', bg: 'bg-church-purple', icon: '⭐' },
  membre:           { label: 'Membres',            color: 'text-green-600',  bg: 'bg-green-500',  icon: '✅' },
};

/* Carte statistique */
const StatCard = ({ icon, label, value, sub, color = 'purple' }) => {
  const colors = {
    purple: 'from-church-purple to-church-purple-lg',
    gold:   'from-church-gold-dk to-church-gold',
    green:  'from-green-600 to-green-400',
    blue:   'from-blue-600 to-blue-400',
  };
  return (
    <div className="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
      <div className="flex items-start justify-between mb-3">
        <p className="text-gray-500 text-sm font-body font-medium">{label}</p>
        <div className={`w-10 h-10 rounded-xl bg-gradient-to-br ${colors[color]} flex items-center justify-center text-white text-lg shrink-0`}>
          {icon}
        </div>
      </div>
      <p className="font-display text-3xl font-bold text-gray-800">{value ?? '—'}</p>
      {sub && <p className="text-xs text-gray-400 font-body mt-1">{sub}</p>}
    </div>
  );
};

/* Barre progression famille */
const FamilyBar = ({ family, count, max }) => {
  const pct = max > 0 ? Math.round((count / max) * 100) : 0;
  const fc  = FAMILY_COLORS[family] || '';
  const fi  = FAMILY_ICONS[family] || '🏠';
  return (
    <div className="flex items-center gap-3">
      <span className="text-sm w-5 text-center">{fi}</span>
      <span className="font-body text-sm text-gray-700 w-20 shrink-0">{family}</span>
      <div className="flex-1 bg-gray-100 rounded-full h-2 overflow-hidden">
        <div className="h-full rounded-full bg-church-purple transition-all duration-700"
             style={{ width: `${pct}%` }} />
      </div>
      <span className={`text-xs font-body font-bold px-2 py-0.5 rounded-full border ${fc}`}>{count}</span>
    </div>
  );
};

/* ─── Composant principal ─────────────────────────────────────────── */
const HomePage = ({ onNavigate }) => {
  const { user } = useAuth();
  const [stats,   setStats]   = useState(null);
  const [loading, setLoading] = useState(true);

  const currentFamily = getCurrentFamily();
  const schedule      = getUpcomingSchedule(6);
  const fc            = FAMILY_COLORS[currentFamily] || '';
  const fi            = FAMILY_ICONS[currentFamily]  || '🏠';

  useEffect(() => {
    getStats()
      .then(({ data }) => setStats(data.data))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const byStatus = (key) => stats?.byStatus?.find(s => s._id === key)?.count ?? 0;

  // Agréger stats familles (v1+v2+v3)
  const familyTotals = {};
  if (stats?.byFamily) {
    ['v1','v2','v3'].forEach(v => {
      (stats.byFamily[v] || []).forEach(({ _id, count }) => {
        familyTotals[_id] = (familyTotals[_id] || 0) + count;
      });
    });
  }
  const maxFamily = Math.max(...Object.values(familyTotals), 1);

  if (loading) return (
    <div className="flex items-center justify-center h-64">
      <div className="flex flex-col items-center gap-3 text-gray-400">
        <svg className="animate-spin w-8 h-8 text-church-purple" fill="none" viewBox="0 0 24 24">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
        <p className="text-sm font-body">Chargement des statistiques…</p>
      </div>
    </div>
  );

  return (
    <div className="flex flex-col gap-6 max-w-6xl">

      {/* Salutation */}
      <div>
        <h1 className="font-display text-2xl font-bold text-gray-800">
          Bonjour, {user?.firstName} 👋
        </h1>
        <p className="text-gray-500 text-sm font-body mt-1">
          Voici un aperçu de l'activité de la Maison de la Destinée.
        </p>
      </div>

      {/* ── Stat cards ── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard icon="👥" label="Total visiteurs"    value={stats?.total}     color="purple"
          sub="depuis le début" />
        <StatCard icon="📅" label="Ce mois-ci"         value={stats?.thisMonth} color="blue"
          sub="nouveaux ce mois" />
        <StatCard icon="⭐" label="Membres potentiels" value={byStatus('membre_potentiel')} color="gold"
          sub="à convertir" />
        <StatCard icon="✅" label="Membres"            value={byStatus('membre')} color="green"
          sub="confirmés" />
      </div>

      {/* ── Ligne du milieu ── */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {/* Progression par statut */}
        <div className="lg:col-span-2 bg-white rounded-2xl p-6 border border-gray-100 shadow-sm">
          <h2 className="font-display text-base font-bold text-gray-700 mb-5">
            Progression des visiteurs
          </h2>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            {Object.entries(STATUS_CONFIG).map(([key, cfg]) => (
              <div key={key} className="text-center">
                <div className={`${cfg.bg} rounded-2xl py-4 mb-2 flex flex-col items-center gap-1`}>
                  <span className="text-2xl">{cfg.icon}</span>
                  <span className="font-display text-2xl font-bold text-white">{byStatus(key)}</span>
                </div>
                <p className="text-xs text-gray-500 font-body font-medium">{cfg.label}</p>
              </div>
            ))}
          </div>
          <button onClick={() => onNavigate('visitors')}
            className="mt-4 text-sm text-church-purple font-body font-semibold hover:text-church-gold-dk
                       transition-colors flex items-center gap-1">
            Voir tous les visiteurs <span>→</span>
          </button>
        </div>

        {/* Famille du mois */}
        <div className={`rounded-2xl p-6 border-2 ${fc} shadow-sm flex flex-col gap-3`}>
          <p className="text-xs font-body font-bold uppercase tracking-widest opacity-70">
            Famille de service
          </p>
          <div className="text-center py-2">
            <div className="text-5xl mb-2">{fi}</div>
            <p className="font-display text-2xl font-bold">{currentFamily}</p>
            <p className="text-xs font-body opacity-70 mt-1">
              {new Date().toLocaleString('fr-FR', { month: 'long', year: 'numeric' })}
            </p>
          </div>
          <div className="gold-divider opacity-30" />
          <p className="text-xs font-body opacity-60 text-center">
            Prochain mois : <strong>{schedule[1]?.family}</strong>
          </p>
        </div>
      </div>

      {/* ── Stats familles + Planning ── */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {/* Stats par famille */}
        <div className="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm">
          <h2 className="font-display text-base font-bold text-gray-700 mb-5">
            Visites par famille d'accueil
          </h2>
          {Object.keys(familyTotals).length === 0 ? (
            <p className="text-sm text-gray-400 font-body text-center py-6">
              Aucune donnée disponible
            </p>
          ) : (
            <div className="flex flex-col gap-3">
              {Object.entries(familyTotals)
                .sort((a, b) => b[1] - a[1])
                .map(([family, count]) => (
                  <FamilyBar key={family} family={family} count={count} max={maxFamily} />
                ))}
            </div>
          )}
        </div>

        {/* Planning rotation 6 mois */}
        <div className="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm">
          <h2 className="font-display text-base font-bold text-gray-700 mb-5">
            Planning des familles — 6 mois
          </h2>
          <div className="flex flex-col gap-2">
            {schedule.map((item, i) => {
              const color = FAMILY_COLORS[item.family] || '';
              const icon  = FAMILY_ICONS[item.family]  || '🏠';
              const isNow = i === 0;
              return (
                <div key={i}
                  className={`flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all
                              ${isNow ? 'bg-church-purple/5 border border-church-purple/20' : 'hover:bg-gray-50'}`}>
                  <span className="text-sm w-5 text-center">{icon}</span>
                  <span className="font-body text-sm text-gray-600 flex-1 capitalize">
                    {item.month} {item.year}
                  </span>
                  <span className={`text-xs font-body font-bold px-2.5 py-1 rounded-full border ${color}`}>
                    {item.family}
                  </span>
                  {isNow && (
                    <span className="text-xs bg-church-purple text-white px-2 py-0.5 rounded-full font-body font-bold">
                      En cours
                    </span>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      </div>
    </div>
  );
};

export default HomePage;