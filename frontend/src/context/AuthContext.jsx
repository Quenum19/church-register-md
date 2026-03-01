import { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { getMe } from '../api/auth';

const AuthContext = createContext(null);

export const AuthProvider = ({ children }) => {
  const [user,    setUser]    = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const token = localStorage.getItem('token');
    if (!token) { setLoading(false); return; }
    getMe()
      .then(({ data }) => setUser(data.data.user))
      .catch(() => localStorage.removeItem('token'))
      .finally(() => setLoading(false));
  }, []);

  const loginUser = useCallback((token, userData) => {
    localStorage.setItem('token', token);
    setUser(userData);
  }, []);

  const logout = useCallback(() => {
    localStorage.removeItem('token');
    setUser(null);
  }, []);

  const isSuperAdmin = user?.role === 'super_admin';
  const isModerator  = user?.role === 'moderateur';
  const canEdit      = isSuperAdmin || isModerator;
  const canDelete    = isSuperAdmin;
  const canManage    = isSuperAdmin;

  return (
    <AuthContext.Provider value={{
      user, loading, loginUser, logout,
      isSuperAdmin, isModerator, canEdit, canDelete, canManage,
    }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => useContext(AuthContext);