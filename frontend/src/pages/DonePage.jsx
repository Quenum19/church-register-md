import logo from '../assets/logo_md.png';

const MESSAGES = {
  1: {
    icon: '🙏', title: 'Bienvenue parmi nous !',
    sub: 'Votre première visite a bien été enregistrée.',
    cta: 'Nous espérons vous revoir dimanche prochain !',
    badge: '1ère visite', badgeColor: 'bg-church-purple/10 text-church-purple border-church-purple/20',
    glow: '#6B1F8A',
  },
  2: {
    icon: '💛', title: 'Ravi de vous revoir !',
    sub: 'Votre deuxième visite a été enregistrée avec succès.',
    cta: 'Vous faites déjà partie de notre communauté.',
    badge: '2ème visite', badgeColor: 'bg-amber-50 text-church-gold-dk border-church-gold/30',
    glow: '#C9A227',
  },
  3: {
    icon: '⭐', title: 'Vous êtes des nôtres !',
    sub: 'Votre troisième visite est enregistrée.',
    cta: 'Approchez un responsable pour la prochaine étape de votre destinée.',
    badge: '3ème visite', badgeColor: 'bg-green-50 text-green-700 border-green-200',
    glow: '#16a34a',
  },
};

const FAMILY_ICONS = {
  Force: '⚡', Honneur: '🏅', Gloire: '✨', Louange: '🎵',
  Puissance: '💪', Richesse: '🌿', Sagesse: '📖',
};

const STARS = Array.from({ length: 20 }, (_, i) => ({
  id: i,
  top:   `${5 + (i * 41) % 88}%`,
  left:  `${5 + (i * 57) % 88}%`,
  delay: `${((i * 0.6) % 4).toFixed(1)}s`,
  size:  `${2 + (i % 3)}px`,
}));

/* ─── Ligne de timeline ───────────────────────────────────────────── */
const TimelineRow = ({ number, visit, color }) => {
  if (!visit?.familleAccueil) return null;
  return (
    <div className="flex items-center gap-3">
      <div className={`w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0 ${color}`}>
        {number}
      </div>
      <div className="flex-1">
        <p className="text-xs text-gray-500 font-body">
          {visit.date ? new Date(visit.date).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) : ''}
        </p>
        <p className="text-sm font-semibold text-gray-700 font-body">
          {FAMILY_ICONS[visit.familleAccueil]} Famille {visit.familleAccueil}
        </p>
      </div>
    </div>
  );
};

/* ─── Composant principal ─────────────────────────────────────────── */
const DonePage = ({ visitCount, visitor, onBack }) => {
  const msg  = MESSAGES[visitCount] || MESSAGES[1];
  const name = visitor?.visit1?.fullName || visitor?.visit2?.fullName || '';
  const city = visitor?.visit1?.city || '';

  const showTimeline = visitor?.visit1?.familleAccueil
                    || visitor?.visit2?.familleAccueil
                    || visitor?.visit3?.familleAccueil;

  return (
    <div className="min-h-screen bg-church-animated flex items-center justify-center p-4 relative overflow-hidden">
      <div className="stars-bg">
        {STARS.map(s => (
          <span key={s.id} className="star" style={{
            top: s.top, left: s.left, width: s.size, height: s.size, animationDelay: s.delay,
          }} />
        ))}
      </div>

      <div className="absolute top-[-60px] left-1/2 -translate-x-1/2 w-96 h-96 rounded-full opacity-20 pointer-events-none"
           style={{ background: `radial-gradient(circle, ${msg.glow} 0%, transparent 70%)` }} />

      <div className="church-card corner-ornament relative w-full max-w-md overflow-hidden">
        <div className="h-1.5 w-full bg-gold-gradient" />

        <div className="p-8 md:p-10">

          {/* Icône */}
          <div className="text-center mb-5 opacity-init animate-fade-up">
            <div className="relative inline-block">
              <div className="absolute inset-[-14px] rounded-full animate-glow pointer-events-none"
                   style={{ background: `radial-gradient(circle, ${msg.glow}33 0%, transparent 70%)` }} />
              <div className="relative z-10 text-6xl">{msg.icon}</div>
            </div>
          </div>

          {/* Badge */}
          <div className="flex justify-center mb-4 opacity-init animate-fade-up-d1">
            <span className={`visit-badge border ${msg.badgeColor}`}>✦ {msg.badge} enregistrée</span>
          </div>

          {/* Titre */}
          <h1 className="font-display text-2xl font-bold text-church-purple text-center mb-2 opacity-init animate-fade-up-d2">
            {msg.title}
          </h1>

          <div className="gold-divider my-4" />

          <p className="text-gray-600 font-body text-sm text-center leading-relaxed mb-1 opacity-init animate-fade-up-d3">
            {msg.sub}
          </p>
          <p className="text-church-purple font-body text-sm font-semibold italic text-center opacity-init animate-fade-up-d3">
            "{msg.cta}"
          </p>

          {/* ── Récapitulatif visiteur ── */}
          {(name || showTimeline) && (
            <div className="mt-5 px-4 py-4 rounded-xl bg-gray-50 border border-gray-100 flex flex-col gap-3 opacity-init animate-fade-up-d3">
              <p className="text-xs font-body font-bold text-gray-400 uppercase tracking-widest">Récapitulatif</p>

              {name && (
                <div className="flex items-center gap-2 text-sm text-gray-700 font-body">
                  <span>👤</span>
                  <span className="font-semibold">{name}</span>
                  {city && <span className="text-gray-400 text-xs">· {city}</span>}
                </div>
              )}

              {/* Timeline familles */}
              {showTimeline && (
                <div className="flex flex-col gap-2 border-t border-gray-100 pt-3">
                  <p className="text-xs text-gray-400 font-body uppercase tracking-wide">Familles d'accueil</p>
                  <TimelineRow number={1} visit={visitor?.visit1} color="bg-church-purple" />
                  <TimelineRow number={2} visit={visitor?.visit2} color="bg-church-gold" />
                  <TimelineRow number={3} visit={visitor?.visit3} color="bg-green-500" />
                </div>
              )}
            </div>
          )}

          {/* Bouton retour */}
          <div className="mt-6 opacity-init animate-fade-up-d4">
            <button onClick={onBack}
              className="w-full flex items-center justify-center gap-2 px-6 py-3 rounded-xl border-2
                         border-church-gold/40 bg-church-gold/5 text-church-gold-dk font-body font-semibold
                         text-sm hover:bg-church-gold/10 hover:border-church-gold transition-all duration-200">
              <span>↩</span>
              <span>Retour à l'accueil</span>
            </button>
          </div>

          {/* Logo bas */}
          <div className="mt-6 flex items-center justify-center gap-2 opacity-20 opacity-init animate-fade-up-d4">
            <img src={logo} alt="MD" className="w-7 h-7 object-contain" />
            <span className="font-display text-xs text-church-purple uppercase tracking-widest">
              La Maison de la Destinée
            </span>
          </div>
        </div>

        <div className="h-1 w-full bg-gold-gradient opacity-60" />
      </div>
    </div>
  );
};

export default DonePage;