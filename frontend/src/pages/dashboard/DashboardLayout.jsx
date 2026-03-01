import { useState, useEffect } from 'react';
import { useAuth } from '../../context/AuthContext';
import { getStats } from '../../api/admin';
import logo from '../../assets/logo_md.png';
import { getCurrentFamily } from '../../utils/familyService';

const FAMILY_ICONS = {
  Force:'⚡', Honneur:'🏅', Gloire:'✨', Louange:'🎵',
  Puissance:'💪', Richesse:'🌿', Sagesse:'📖',
};

const NAV_ITEMS = [
  { key: 'home',     icon: '🏠', label: 'Accueil',    roles: ['super_admin','moderateur','lecteur'] },
  { key: 'visitors', icon: '👥', label: 'Visiteurs',   roles: ['super_admin','moderateur','lecteur'], hasBadge: true },
  { key: 'members',  icon: '✅', label: 'Membres',     roles: ['super_admin','moderateur','lecteur'] },
  { key: 'reports',  icon: '📊', label: 'Rapports',    roles: ['super_admin','moderateur','lecteur'] },
  { key: 'qrcode',   icon: '📲', label: 'QR Code',     roles: ['super_admin','moderateur'] },
  { key: 'admins',   icon: '🔑', label: 'Admins',      roles: ['super_admin'] },
  { key: 'settings', icon: '⚙️', label: 'Paramètres',  roles: ['super_admin','moderateur','lecteur'] },
];

const ROLE_LABELS = {
  super_admin: { label: 'Super Admin', color: 'text-amber-700 bg-amber-50 border-amber-200' },
  moderateur:  { label: 'Modérateur',  color: 'text-blue-700 bg-blue-50 border-blue-200' },
  lecteur:     { label: 'Lecteur',     color: 'text-gray-600 bg-gray-50 border-gray-200' },
};

const DashboardLayout = ({ activePage, onNavigate, children }) => {
  const { user, logout } = useAuth();
  const [collapsed,  setCollapsed]  = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [todayCount, setTodayCount] = useState(0);
  const currentFamily = getCurrentFamily();
  const fi = FAMILY_ICONS[currentFamily] || '🏠';

  useEffect(() => {
    getStats().then(({ data }) => setTodayCount(data.data?.today ?? 0)).catch(() => {});
    // Rafraîchir toutes les 5 minutes
    const iv = setInterval(() => {
      getStats().then(({ data }) => setTodayCount(data.data?.today ?? 0)).catch(() => {});
    }, 300000);
    return () => clearInterval(iv);
  }, []);

  const filteredNav = NAV_ITEMS.filter(item => item.roles.includes(user?.role));
  const roleInfo    = ROLE_LABELS[user?.role] || ROLE_LABELS.lecteur;

  const NavItem = ({ item }) => {
    const isActive = activePage === item.key;
    return (
      <button onClick={() => { onNavigate(item.key); setMobileOpen(false); }}
        title={collapsed ? item.label : undefined}
        className={`w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-body
                    font-medium transition-all duration-200 relative group
                    ${isActive
                      ? 'bg-white text-church-purple shadow-sm'
                      : 'text-purple-200 hover:bg-white/10 hover:text-white'}`}>
        <span className="text-base shrink-0">{item.icon}</span>
        {!collapsed && <span className="flex-1 text-left">{item.label}</span>}
        {item.hasBadge && todayCount > 0 && (
          <span className="shrink-0 bg-church-gold text-church-purple-dk text-xs font-bold
                           px-1.5 py-0.5 rounded-full min-w-[20px] text-center leading-none">
            {todayCount}
          </span>
        )}
        {collapsed && (
          <span className="absolute left-full ml-2 px-2 py-1 bg-gray-900 text-white text-xs
                           rounded-lg opacity-0 group-hover:opacity-100 transition-opacity
                           pointer-events-none whitespace-nowrap z-50 shadow-lg">
            {item.label}
          </span>
        )}
      </button>
    );
  };

  const SidebarContent = () => (
    <div className="flex flex-col h-full">
      {/* Logo + toggle */}
      <div className={`flex items-center border-b border-white/10 shrink-0
                       ${collapsed ? 'px-3 py-4 justify-center' : 'px-4 py-4 gap-3'}`}>
        <img src={logo} alt="MD" className="w-9 h-9 object-contain shrink-0" />
        {!collapsed && (
          <div className="flex-1 min-w-0">
            <p className="font-display text-xs font-bold text-white uppercase tracking-wide leading-tight truncate">
              Maison de la Destinée
            </p>
            <p className="text-purple-300 text-xs font-body">Administration</p>
          </div>
        )}
        <button onClick={() => setCollapsed(p => !p)}
          className="shrink-0 w-7 h-7 rounded-lg bg-white/10 flex items-center justify-center
                     text-purple-300 hover:text-white hover:bg-white/20 transition-all text-xs">
          {collapsed ? '→' : '←'}
        </button>
      </div>

      {/* Famille du mois */}
      <div className={`mx-2 mt-3 rounded-xl bg-white/10 border border-white/10 shrink-0
                       ${collapsed ? 'p-2 text-center' : 'px-3 py-2.5'}`}
           title={collapsed ? `Famille ${currentFamily}` : undefined}>
        {collapsed
          ? <span className="text-lg">{fi}</span>
          : <>
              <p className="text-purple-400 text-xs font-body uppercase tracking-wide">Famille du mois</p>
              <p className="text-white text-sm font-body font-semibold mt-0.5">{fi} {currentFamily}</p>
            </>}
      </div>

      {/* Navigation */}
      <nav className="flex-1 p-3 flex flex-col gap-0.5 overflow-y-auto mt-2">
        {filteredNav.map(item => <NavItem key={item.key} item={item} />)}
      </nav>

      {/* Profil + Déconnexion */}
      <div className="border-t border-white/10 p-3 shrink-0">
        {!collapsed && (
          <div className="mb-2 px-2">
            <p className="text-sm font-body font-semibold text-white truncate">
              {user?.firstName} {user?.lastName}
            </p>
            <span className={`text-xs font-body font-medium px-2 py-0.5 rounded-full border ${roleInfo.color} mt-0.5 inline-block`}>
              {roleInfo.label}
            </span>
          </div>
        )}
        <button onClick={logout} title={collapsed ? 'Se déconnecter' : undefined}
          className={`w-full flex items-center gap-2 px-3 py-2.5 rounded-xl text-sm text-purple-300
                      hover:text-white hover:bg-white/10 transition-all font-body
                      ${collapsed ? 'justify-center' : ''}`}>
          <span className="shrink-0">🚪</span>
          {!collapsed && <span>Déconnexion</span>}
        </button>
      </div>
    </div>
  );

  return (
    <div className="flex min-h-screen bg-gray-50">
      {/* Sidebar desktop */}
      <aside className={`hidden md:flex flex-col bg-church-purple-dk transition-all duration-300 shrink-0
                         ${collapsed ? 'w-16' : 'w-60'}`}>
        <SidebarContent />
      </aside>

      {/* Sidebar mobile overlay */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 flex md:hidden">
          <div className="absolute inset-0 bg-black/50" onClick={() => setMobileOpen(false)} />
          <aside className="relative w-64 bg-church-purple-dk flex flex-col h-full z-10">
            <SidebarContent />
          </aside>
        </div>
      )}

      {/* Contenu principal */}
      <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
        {/* Header */}
        <header className="bg-white border-b border-gray-100 px-4 md:px-6 py-3 flex items-center gap-4 shrink-0 shadow-sm">
          <button onClick={() => setMobileOpen(true)}
            className="md:hidden w-8 h-8 rounded-lg border border-gray-200 flex items-center justify-center
                       text-gray-500 hover:border-church-purple/40 hover:text-church-purple transition-all">
            ☰
          </button>

          <div className="flex-1">
            <p className="font-display text-base font-bold text-church-purple-dk">
              {NAV_ITEMS.find(n => n.key === activePage)?.label ?? 'Dashboard'}
            </p>
            <p className="text-xs text-gray-400 font-body hidden sm:block">
              {new Date().toLocaleDateString('fr-FR', { weekday:'long', day:'numeric', month:'long', year:'numeric' })}
            </p>
          </div>

          {/* Badge nouveaux visiteurs */}
          {todayCount > 0 && (
            <button onClick={() => onNavigate('visitors')}
              className="flex items-center gap-2 px-3 py-1.5 rounded-full bg-amber-50 border border-church-gold/40
                         text-church-gold-dk text-xs font-body font-semibold hover:bg-amber-100 transition-all">
              <span className="w-2 h-2 rounded-full bg-church-gold animate-pulse inline-block" />
              {todayCount} nouveau{todayCount > 1 ? 'x' : ''} aujourd'hui
            </button>
          )}

          {/* Avatar */}
          <div className="w-8 h-8 rounded-full bg-church-purple flex items-center justify-center
                          text-white text-xs font-bold shrink-0 cursor-pointer"
               onClick={() => onNavigate('settings')}
               title="Paramètres">
            {user?.firstName?.[0]}{user?.lastName?.[0]}
          </div>
        </header>

        {/* Page */}
        <main className="flex-1 overflow-auto p-4 md:p-6">
          {children}
        </main>
      </div>
    </div>
  );
};

export default DashboardLayout;