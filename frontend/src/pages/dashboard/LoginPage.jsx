import { useState } from 'react';
import { login } from '../../api/auth';
import { useAuth } from '../../context/AuthContext';
import logo from '../../assets/logo_md.png';

const STARS = Array.from({ length: 16 }, (_, i) => ({
  id: i, top: `${5+(i*37)%88}%`, left: `${5+(i*53)%88}%`,
  delay: `${((i*0.7)%4).toFixed(1)}s`, size: `${2+(i%3)}px`,
}));

const LoginPage = () => {
  const { loginUser } = useAuth();
  const [form,    setForm]    = useState({ email: '', password: '' });
  const [loading, setLoading] = useState(false);
  const [error,   setError]   = useState('');
  const [showPw,  setShowPw]  = useState(false);

  const set = (f) => (e) => { setForm(p => ({ ...p, [f]: e.target.value })); setError(''); };

  const handleSubmit = async () => {
    setError('');
    if (!form.email || !form.password) { setError('Email et mot de passe requis.'); return; }
    setLoading(true);
    try {
      const { data } = await login(form);
      loginUser(data.data.token, data.data.user);
    } catch (err) {
      setError(err.response?.data?.message || 'Identifiants incorrects.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-church-animated flex items-center justify-center p-4 relative overflow-hidden">
      {/* Étoiles */}
      <div className="stars-bg">
        {STARS.map(s => (
          <span key={s.id} className="star" style={{
            top: s.top, left: s.left, width: s.size, height: s.size, animationDelay: s.delay,
          }} />
        ))}
      </div>

      <div className="absolute top-[-80px] right-[-80px] w-72 h-72 rounded-full opacity-20 pointer-events-none"
           style={{ background: 'radial-gradient(circle, #C9A227 0%, transparent 70%)' }} />

      <div className="church-card corner-ornament relative w-full max-w-sm overflow-hidden">
        <div className="h-1.5 w-full bg-gold-gradient" />

        <div className="p-8">
          {/* Logo */}
          <div className="text-center mb-8">
            <div className="relative inline-block mb-4">
              <div className="absolute inset-[-8px] rounded-full animate-glow pointer-events-none"
                   style={{ background: 'radial-gradient(circle, rgba(201,162,39,0.2) 0%, transparent 70%)' }} />
              <img src={logo} alt="MD" className="w-16 h-16 object-contain relative z-10" />
            </div>
            <h1 className="font-display text-xl font-bold text-church-purple">Administration</h1>
            <p className="text-xs text-gray-400 font-body mt-1">La Maison de la Destinée</p>
            <div className="gold-divider mt-3" />
          </div>

          {/* Formulaire */}
          <div className="flex flex-col gap-4">
            {/* Email */}
            <div>
              <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-1.5">
                Email
              </p>
              <input type="email" placeholder="admin@eglise.com" value={form.email}
                onChange={set('email')}
                onKeyDown={(e) => e.key === 'Enter' && handleSubmit()}
                className="church-input" />
            </div>

            {/* Mot de passe */}
            <div>
              <p className="text-xs font-body font-bold text-church-purple uppercase tracking-widest mb-1.5">
                Mot de passe
              </p>
              <div className="relative">
                <input type={showPw ? 'text' : 'password'} placeholder="••••••••"
                  value={form.password} onChange={set('password')}
                  onKeyDown={(e) => e.key === 'Enter' && handleSubmit()}
                  className="church-input pr-10" />
                <button type="button" onClick={() => setShowPw(p => !p)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-church-purple transition-colors text-sm">
                  {showPw ? '🙈' : '👁️'}
                </button>
              </div>
            </div>

            {error && (
              <div className="flex items-center gap-2 px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm font-body">
                <span className="font-bold shrink-0">✕</span>
                <p>{error}</p>
              </div>
            )}

            <button onClick={handleSubmit} disabled={loading}
              className="btn-gold w-full text-center mt-1">
              {loading
                ? <span className="flex items-center justify-center gap-2">
                    <svg className="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    Connexion…
                  </span>
                : 'Se connecter →'}
            </button>
          </div>
        </div>

        <div className="h-1 w-full bg-gold-gradient opacity-60" />
      </div>
    </div>
  );
};

export default LoginPage;