import { useEffect, useState } from 'react';
import { getAdmins, createAdmin, updateAdmin, deleteAdmin } from '../../api/auth';
import { useAuth } from '../../context/AuthContext';

const ROLES = [
  { value: 'super_admin', label: 'Super Admin',  desc: 'Accès total',                         color: 'bg-amber-50 text-amber-700 border-amber-200' },
  { value: 'moderateur',  label: 'Modérateur',   desc: 'Lecture + modification',               color: 'bg-blue-50 text-blue-700 border-blue-200' },
  { value: 'lecteur',     label: 'Lecteur',       desc: 'Lecture seule',                        color: 'bg-gray-50 text-gray-600 border-gray-200' },
];

const RoleBadge = ({ role }) => {
  const r = ROLES.find(x => x.value === role);
  if (!r) return null;
  return (
    <span className={`text-xs font-body font-bold px-2.5 py-1 rounded-full border ${r.color}`}>
      {r.label}
    </span>
  );
};

/* Modal création / édition admin */
const AdminModal = ({ admin, onClose, onSaved }) => {
  const isEdit = !!admin?._id;
  const [form,    setForm]    = useState({
    firstName: admin?.firstName || '',
    lastName:  admin?.lastName  || '',
    email:     admin?.email     || '',
    role:      admin?.role      || 'lecteur',
    password:  '',
  });
  const [loading, setLoading] = useState(false);
  const [error,   setError]   = useState('');

  const set = (f) => (e) => { setForm(p => ({ ...p, [f]: e.target.value })); setError(''); };

  const handleSubmit = async () => {
    setError('');
    if (!form.firstName || !form.lastName || !form.email) { setError('Tous les champs sont obligatoires.'); return; }
    if (!isEdit && !form.password) { setError('Le mot de passe est requis.'); return; }
    if (form.password && form.password.length < 8) { setError('Minimum 8 caractères pour le mot de passe.'); return; }

    setLoading(true);
    try {
      const payload = { ...form };
      if (isEdit && !payload.password) delete payload.password;

      if (isEdit) await updateAdmin(admin._id, payload);
      else        await createAdmin(payload);

      onSaved();
    } catch (err) {
      setError(err.response?.data?.message || 'Erreur lors de l\'enregistrement.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-2xl p-6 max-w-md w-full shadow-xl">
        <div className="flex items-center justify-between mb-5">
          <h3 className="font-display text-lg font-bold text-gray-800">
            {isEdit ? 'Modifier l\'administrateur' : 'Nouvel administrateur'}
          </h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
        </div>

        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-1.5">Prénom</p>
              <input type="text" value={form.firstName} onChange={set('firstName')} placeholder="Jean"
                className="church-input" />
            </div>
            <div>
              <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-1.5">Nom</p>
              <input type="text" value={form.lastName} onChange={set('lastName')} placeholder="Kouamé"
                className="church-input" />
            </div>
          </div>

          <div>
            <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-1.5">Email</p>
            <input type="email" value={form.email} onChange={set('email')} placeholder="jean@eglise.com"
              className="church-input" />
          </div>

          <div>
            <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-1.5">Rôle</p>
            <div className="flex flex-col gap-2">
              {ROLES.map(r => (
                <label key={r.value}
                  className={`flex items-center gap-3 px-4 py-3 rounded-xl border-2 cursor-pointer transition-all
                    ${form.role === r.value ? 'border-church-purple bg-purple-50/60' : 'border-gray-200 hover:border-church-purple/30'}`}>
                  <input type="radio" name="role" value={r.value} checked={form.role === r.value}
                    onChange={set('role')} className="sr-only" />
                  <div className={`w-4 h-4 rounded-full border-2 flex items-center justify-center transition-all shrink-0
                    ${form.role === r.value ? 'border-church-purple bg-church-purple' : 'border-gray-300'}`}>
                    {form.role === r.value && <span className="w-2 h-2 rounded-full bg-white block" />}
                  </div>
                  <div className="flex-1">
                    <p className="text-sm font-body font-semibold text-gray-700">{r.label}</p>
                    <p className="text-xs text-gray-400 font-body">{r.desc}</p>
                  </div>
                  <span className={`text-xs font-body font-bold px-2 py-0.5 rounded-full border ${r.color}`}>{r.label}</span>
                </label>
              ))}
            </div>
          </div>

          <div>
            <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-1.5">
              Mot de passe {isEdit && <span className="normal-case text-gray-400">(laisser vide pour ne pas changer)</span>}
            </p>
            <input type="password" value={form.password} onChange={set('password')}
              placeholder={isEdit ? '••••••••' : 'Minimum 8 caractères'}
              className="church-input" />
          </div>

          {error && (
            <div className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm font-body">
              ✕ {error}
            </div>
          )}

          <div className="flex gap-3 pt-1">
            <button onClick={onClose}
              className="flex-1 px-4 py-2.5 rounded-xl border-2 border-gray-200 text-gray-600 font-body font-semibold text-sm hover:border-church-purple/40 transition-all">
              Annuler
            </button>
            <button onClick={handleSubmit} disabled={loading}
              className="flex-1 btn-gold text-sm">
              {loading ? 'Enregistrement…' : isEdit ? 'Mettre à jour' : 'Créer le compte'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

/* ─── Composant principal ─────────────────────────────────────────── */
const AdminsPage = () => {
  const { user: currentUser } = useAuth();
  const [admins,  setAdmins]  = useState([]);
  const [loading, setLoading] = useState(true);
  const [modal,   setModal]   = useState(null);   // null | {} | admin object
  const [confirm, setConfirm] = useState(null);   // admin à supprimer

  const load = () => {
    setLoading(true);
    getAdmins()
      .then(({ data }) => setAdmins(data.data.users))
      .catch(() => {})
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  const handleDelete = async (admin) => {
    await deleteAdmin(admin._id);
    setConfirm(null);
    load();
  };

  return (
    <div className="max-w-4xl flex flex-col gap-6">
      {/* En-tête */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="font-display text-xl font-bold text-gray-800">Gestion des admins</h1>
          <p className="text-gray-400 text-sm font-body">{admins.length} compte{admins.length > 1 ? 's' : ''} configuré{admins.length > 1 ? 's' : ''}</p>
        </div>
        <button onClick={() => setModal({})}
          className="btn-gold text-sm px-5 py-2.5">
          + Nouvel admin
        </button>
      </div>

      {/* Explication rôles */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        {ROLES.map(r => (
          <div key={r.value} className={`rounded-xl border-2 p-4 ${r.color}`}>
            <p className="font-body font-bold text-sm">{r.label}</p>
            <p className="text-xs font-body opacity-70 mt-0.5">{r.desc}</p>
          </div>
        ))}
      </div>

      {/* Liste admins */}
      {loading ? (
        <div className="flex items-center justify-center py-16 text-gray-400">
          <svg className="animate-spin w-5 h-5 mr-2 text-church-purple" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          <span className="text-sm font-body">Chargement…</span>
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          {admins.map(admin => {
            const isSelf = admin._id === currentUser?._id;
            return (
              <div key={admin._id}
                className="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm flex items-center gap-4">
                {/* Avatar */}
                <div className="w-11 h-11 rounded-full bg-church-purple/10 flex items-center justify-center
                                text-church-purple font-display font-bold shrink-0">
                  {admin.firstName?.[0]}{admin.lastName?.[0]}
                </div>

                {/* Infos */}
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <p className="font-body font-semibold text-gray-800 text-sm">
                      {admin.firstName} {admin.lastName}
                    </p>
                    {isSelf && (
                      <span className="text-xs bg-purple-100 text-church-purple px-2 py-0.5 rounded-full font-body">
                        Vous
                      </span>
                    )}
                    <RoleBadge role={admin.role} />
                  </div>
                  <p className="text-xs text-gray-400 font-body mt-0.5">{admin.email}</p>
                  <p className="text-xs text-gray-300 font-body mt-0.5">
                    Créé le {new Date(admin.createdAt).toLocaleDateString('fr-FR')}
                  </p>
                </div>

                {/* Actions */}
                {!isSelf && (
                  <div className="flex items-center gap-2 shrink-0">
                    <button onClick={() => setModal(admin)}
                      className="px-3 py-2 rounded-xl border-2 border-gray-200 text-xs text-gray-600 font-body
                                 font-semibold hover:border-church-purple/40 hover:text-church-purple transition-all">
                      Modifier
                    </button>
                    <button onClick={() => setConfirm(admin)}
                      className="px-3 py-2 rounded-xl border-2 border-red-100 text-xs text-red-400 font-body
                                 font-semibold hover:bg-red-50 hover:border-red-200 transition-all">
                      Suppr.
                    </button>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {/* Modal création/édition */}
      {modal !== null && (
        <AdminModal
          admin={Object.keys(modal).length > 0 ? modal : null}
          onClose={() => setModal(null)}
          onSaved={() => { setModal(null); load(); }}
        />
      )}

      {/* Modal suppression */}
      {confirm && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl p-6 max-w-sm w-full shadow-xl">
            <p className="text-4xl text-center mb-3">🗑️</p>
            <h3 className="font-display text-lg font-bold text-gray-800 text-center mb-2">Supprimer ce compte</h3>
            <p className="text-sm text-gray-500 font-body text-center mb-5">
              Supprimer le compte de <strong>{confirm.firstName} {confirm.lastName}</strong> ? Cette action est irréversible.
            </p>
            <div className="flex gap-3">
              <button onClick={() => setConfirm(null)}
                className="flex-1 px-4 py-2.5 rounded-xl border-2 border-gray-200 text-gray-600 font-body font-semibold text-sm hover:border-church-purple/40 transition-all">
                Annuler
              </button>
              <button onClick={() => handleDelete(confirm)}
                className="flex-1 px-4 py-2.5 rounded-xl bg-red-500 text-white font-body font-semibold text-sm hover:bg-red-600 transition-all">
                Supprimer
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default AdminsPage;