import { useState, useEffect } from 'react';
import { AuthProvider, useAuth } from './context/AuthContext';

import LoginPage       from './pages/dashboard/LoginPage';
import DashboardLayout from './pages/dashboard/DashboardLayout';
import HomePage        from './pages/dashboard/HomePage';
import VisitorsPage    from './pages/dashboard/VisitorsPage';
import MembersPage     from './pages/dashboard/MembersPage';
import VisitorDetail   from './pages/dashboard/VisitorDetail';
import AdminsPage      from './pages/dashboard/AdminsPage';
import SettingsPage    from './pages/dashboard/SettingsPage';
import ReportsPage     from './pages/dashboard/ReportsPage';
import QRCodePage      from './pages/dashboard/QRCodePage';

const DashboardRouter = () => {
  const { user, loading } = useAuth();
  const [page,       setPage]       = useState('home');
  const [selectedId, setSelectedId] = useState(null);

  // Lire le hash URL pour la navigation directe (ex: /admin#reports)
  useEffect(() => {
    const hash = window.location.hash.replace('#', '');
    if (['home','visitors','members','reports','admins','settings'].includes(hash)) {
      setPage(hash);
    }
  }, []);

  if (loading) return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center">
      <div className="flex flex-col items-center gap-3 text-gray-400">
        <svg className="animate-spin w-8 h-8 text-church-purple" fill="none" viewBox="0 0 24 24">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
        <p className="text-sm font-body">Chargement…</p>
      </div>
    </div>
  );

  if (!user) return <LoginPage />;

  const navigate = (p) => {
    setPage(p);
    setSelectedId(null);
    window.location.hash = p;
  };

  const openVisitor = (id) => {
    setSelectedId(id);
    setPage('visitor-detail');
  };

  const activePage = selectedId ? (page === 'visitor-detail' ? 'visitors' : page) : page;

  return (
    <DashboardLayout activePage={activePage} onNavigate={navigate}>
      {page === 'home'           && <HomePage onNavigate={navigate} />}
      {page === 'visitors'       && !selectedId && <VisitorsPage onSelectVisitor={openVisitor} />}
      {page === 'members'        && !selectedId && <MembersPage  onSelectVisitor={openVisitor} />}
      {page === 'visitor-detail' && selectedId  &&
        <VisitorDetail visitorId={selectedId}
          onBack={() => { setSelectedId(null); setPage(activePage === 'members' ? 'members' : 'visitors'); }} />}
      {page === 'reports'   && <ReportsPage />}
      {page === 'admins'    && user.role === 'super_admin' && <AdminsPage />}
      {page === 'admins'    && user.role !== 'super_admin' && (
        <div className="text-center py-16 text-gray-400">
          <p className="text-4xl mb-3">🔒</p>
          <p className="text-sm font-body">Accès réservé aux Super Admins.</p>
        </div>
      )}
      {page === 'qrcode'    && <QRCodePage />}
      {page === 'settings'  && <SettingsPage />}
    </DashboardLayout>
  );
};

const DashboardApp = () => (
  <AuthProvider>
    <DashboardRouter />
  </AuthProvider>
);

export default DashboardApp;