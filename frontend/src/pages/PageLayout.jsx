import { useMemo } from 'react';
import logo from '../assets/logo_md.png';
import { getCurrentFamily } from '../utils/familyService';

/* Étoiles déterministes (pas de Math.random → pas de re-render) */
const STARS = Array.from({ length: 14 }, (_, i) => ({
  id:    i,
  top:   `${5 + (i * 37) % 85}%`,
  left:  `${5 + (i * 53) % 88}%`,
  delay: `${((i * 0.7) % 4).toFixed(1)}s`,
  size:  `${2 + (i % 3)}px`,
}));

/* Badges de progression par numéro de visite */
const VISIT_BADGES = {
  1: { label: '1er Dimanche',  dot: '#6B1F8A', color: 'bg-church-purple/10 text-church-purple border-church-purple/25' },
  2: { label: '2ème Dimanche', dot: '#C9A227', color: 'bg-amber-50 text-church-gold-dk border-church-gold/30' },
  3: { label: '3ème Dimanche', dot: '#16a34a', color: 'bg-green-50 text-green-700 border-green-200' },
};

/* Couleur associée à chaque famille */
const FAMILY_COLORS = {
  Force:     { bg: 'bg-red-50',    text: 'text-red-700',    border: 'border-red-200',    icon: '⚡' },
  Honneur:   { bg: 'bg-blue-50',   text: 'text-blue-700',   border: 'border-blue-200',   icon: '🏅' },
  Gloire:    { bg: 'bg-yellow-50', text: 'text-yellow-700', border: 'border-yellow-200', icon: '✨' },
  Louange:   { bg: 'bg-pink-50',   text: 'text-pink-700',   border: 'border-pink-200',   icon: '🎵' },
  Puissance: { bg: 'bg-orange-50', text: 'text-orange-700', border: 'border-orange-200', icon: '💪' },
  Richesse:  { bg: 'bg-emerald-50',text: 'text-emerald-700',border: 'border-emerald-200',icon: '🌿' },
  Sagesse:   { bg: 'bg-purple-50', text: 'text-purple-700', border: 'border-purple-200', icon: '📖' },
};

const PageLayout = ({ visitNumber, title, subtitle, onBack, children }) => {
  const badge  = VISIT_BADGES[visitNumber];
  const family = useMemo(() => getCurrentFamily(), []);
  const fc     = FAMILY_COLORS[family] || FAMILY_COLORS.Force;

  return (
    <div className="min-h-screen bg-church-animated flex items-center justify-center p-4 relative overflow-hidden">

      {/* Étoiles décoratives */}
      <div className="stars-bg">
        {STARS.map(s => (
          <span key={s.id} className="star" style={{
            top: s.top, left: s.left, width: s.size, height: s.size, animationDelay: s.delay,
          }} />
        ))}
      </div>

      {/* Orbe lumineux */}
      <div className="absolute top-[-80px] right-[-80px] w-72 h-72 rounded-full opacity-15 pointer-events-none"
           style={{ background: 'radial-gradient(circle, #C9A227 0%, transparent 70%)' }} />

      <div className="church-card corner-ornament relative w-full max-w-lg overflow-hidden my-6">
        {/* Bandeau doré */}
        <div className="h-1.5 w-full bg-gold-gradient" />

        <div className="p-7 md:p-8">

          {/* ── En-tête ── */}
          <div className="flex items-start justify-between mb-5 opacity-init animate-fade-up">

            {/* Logo + nom église */}
            <div className="flex items-center gap-3">
              <img src={logo} alt="MD" className="w-10 h-10 object-contain shrink-0" />
              <div>
                <p className="font-display text-xs font-semibold text-church-purple uppercase tracking-wider leading-snug">
                  La Maison<br />de la Destinée
                </p>
              </div>
            </div>

            {/* Badges + bouton retour */}
            <div className="flex flex-col items-end gap-1.5">
              {/* Badge progression */}
              <span className={`visit-badge border ${badge.color}`}>
                <span className="w-1.5 h-1.5 rounded-full inline-block shrink-0" style={{ backgroundColor: badge.dot }} />
                {badge.label}
              </span>

              {/* Badge famille de service */}
              <span className={`visit-badge border ${fc.bg} ${fc.text} ${fc.border}`}>
                <span className="text-xs">{fc.icon}</span>
                Famille {family}
              </span>
            </div>
          </div>

          {/* Bouton retour accueil */}
          {onBack && (
            <button
              onClick={onBack}
              className="flex items-center gap-1.5 text-xs text-gray-400 hover:text-church-purple
                         font-body transition-colors duration-200 mb-4 group"
            >
              <span className="group-hover:-translate-x-0.5 transition-transform duration-200">←</span>
              <span>Retour à l'accueil</span>
            </button>
          )}

          <div className="gold-divider mb-5" />

          {/* Titre */}
          <div className="mb-6 opacity-init animate-fade-up-d1">
            <h2 className="font-display text-2xl font-bold text-church-purple-dk">{title}</h2>
            {subtitle && (
              <p className="text-gray-500 text-sm font-body mt-1 leading-relaxed">{subtitle}</p>
            )}
          </div>

          {/* Contenu injecté */}
          <div className="opacity-init animate-fade-up-d2">
            {children}
          </div>
        </div>

        <div className="h-1 w-full bg-gold-gradient opacity-60" />
      </div>
    </div>
  );
};

export default PageLayout;