import { useState } from 'react';
import { changePassword } from '../../api/auth';
import { useAuth } from '../../context/AuthContext';
import { getCurrentFamily, getUpcomingSchedule } from '../../utils/familyService';

const FAMILY_ICONS = {
  Force: '⚡', Honneur: '🏅', Gloire: '✨', Louange: '🎵',
  Puissance: '💪', Richesse: '🌿', Sagesse: '📖',
};

const SettingsPage = () => {
  const { user, logout } = useAuth();
  const [form,    setForm]    = useState({ currentPassword: '', newPassword: '', confirmPassword: '' });
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState('');
  const [error,   setError]   = useState('');
  const [showPw,  setShowPw]  = useState(false);

  const set = (f) => (e) => { setForm(p => ({ ...p, [f]: e.target.value })); setError(''); setSuccess(''); };

  const handlePasswordChange = async () => {
    setError(''); setSuccess('');
    if (!form.currentPassword || !form.newPassword || !form.confirmPassword) {
      setError('Tous les champs sont requis.'); return;
    }
    if (form.newPassword.length < 8) { setError('Le nouveau mot de passe doit contenir au moins 8 caractères.'); return; }
    if (form.newPassword !== form.confirmPassword) { setError('Les mots de passe ne correspondent pas.'); return; }

    setLoading(true);
    try {
      await changePassword({ currentPassword: form.currentPassword, newPassword: form.newPassword });
      setSuccess('Mot de passe mis à jour avec succès !');
      setForm({ currentPassword: '', newPassword: '', confirmPassword: '' });
    } catch (err) {
      setError(err.response?.data?.message || 'Erreur lors du changement de mot de passe.');
    } finally {
      setLoading(false);
    }
  };

  const currentFamily = getCurrentFamily();
  const schedule      = getUpcomingSchedule(12);

  return (
    <div className="max-w-3xl flex flex-col gap-6">
      <div>
        <h1 className="font-display text-xl font-bold text-gray-800">Paramètres</h1>
        <p className="text-gray-400 text-sm font-body mt-0.5">Gérez votre profil et les préférences.</p>
      </div>

      {/* Profil */}
      <div className="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm">
        <h2 className="font-display text-base font-bold text-gray-700 mb-4">Mon profil</h2>
        <div className="flex items-center gap-4">
          <div className="w-14 h-14 rounded-full bg-church-purple/10 flex items-center justify-center
                          text-church-purple font-display font-bold text-xl shrink-0">
            {user?.firstName?.[0]}{user?.lastName?.[0]}
          </div>
          <div>
            <p className="font-body font-bold text-gray-800">{user?.firstName} {user?.lastName}</p>
            <p className="text-sm text-gray-400 font-body">{user?.email}</p>
            <span className={`text-xs font-body font-bold px-2.5 py-1 rounded-full border mt-1 inline-block
              ${{ super_admin: 'bg-amber-50 text-amber-700 border-amber-200',
                  moderateur: 'bg-blue-50 text-blue-700 border-blue-200',
                  lecteur: 'bg-gray-50 text-gray-600 border-gray-200' }[user?.role] || ''}`}>
              {user?.role === 'super_admin' ? 'Super Admin' : user?.role === 'moderateur' ? 'Modérateur' : 'Lecteur'}
            </span>
          </div>
        </div>
      </div>

      {/* Changement mot de passe */}
      <div className="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm">
        <h2 className="font-display text-base font-bold text-gray-700 mb-4">Changer le mot de passe</h2>
        <div className="flex flex-col gap-4 max-w-sm">
          {[
            { f: 'currentPassword', label: 'Mot de passe actuel' },
            { f: 'newPassword',     label: 'Nouveau mot de passe' },
            { f: 'confirmPassword', label: 'Confirmer le nouveau mot de passe' },
          ].map(({ f, label }) => (
            <div key={f}>
              <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-1.5">{label}</p>
              <div className="relative">
                <input type={showPw ? 'text' : 'password'} value={form[f]} onChange={set(f)}
                  placeholder="••••••••" className="church-input pr-10" />
              </div>
            </div>
          ))}

          <label className="flex items-center gap-2 text-sm text-gray-500 font-body cursor-pointer select-none">
            <input type="checkbox" checked={showPw} onChange={e => setShowPw(e.target.checked)}
              className="rounded border-gray-300" />
            Afficher les mots de passe
          </label>

          {error   && <div className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm font-body">✕ {error}</div>}
          {success && <div className="px-4 py-3 rounded-xl bg-green-50 border border-green-200 text-green-700 text-sm font-body">✓ {success}</div>}

          <button onClick={handlePasswordChange} disabled={loading} className="btn-gold text-sm w-fit px-6 py-2.5">
            {loading ? 'Mise à jour…' : 'Mettre à jour'}
          </button>
        </div>
      </div>

      {/* Planning complet familles */}
      <div className="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm">
        <h2 className="font-display text-base font-bold text-gray-700 mb-4">Planning annuel des familles</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
          {schedule.map((item, i) => {
            const isNow = i === 0;
            const icon  = FAMILY_ICONS[item.family] || '🏠';
            return (
              <div key={i}
                className={`flex items-center gap-3 px-4 py-3 rounded-xl transition-all
                  ${isNow ? 'bg-church-purple text-white' : 'bg-gray-50 hover:bg-gray-100'}`}>
                <span className="text-base">{icon}</span>
                <span className={`font-body text-sm flex-1 capitalize ${isNow ? 'text-white font-semibold' : 'text-gray-600'}`}>
                  {item.month} {item.year}
                </span>
                <span className={`text-xs font-body font-bold ${isNow ? 'text-church-gold' : 'text-gray-500'}`}>
                  {item.family}
                </span>
                {isNow && <span className="text-xs bg-church-gold text-church-purple-dk px-2 py-0.5 rounded-full font-bold">En cours</span>}
              </div>
            );
          })}
        </div>
      </div>

      {/* Danger zone */}
      <div className="bg-white rounded-2xl p-6 border border-red-100 shadow-sm">
        <h2 className="font-display text-base font-bold text-red-500 mb-2">Zone de déconnexion</h2>
        <p className="text-sm text-gray-400 font-body mb-4">Vous serez redirigé vers la page de connexion.</p>
        <button onClick={logout}
          className="px-5 py-2.5 rounded-xl border-2 border-red-200 text-red-500 font-body font-semibold text-sm hover:bg-red-50 transition-all">
          🚪 Se déconnecter
        </button>
      </div>
    </div>
  );
};

export default SettingsPage;