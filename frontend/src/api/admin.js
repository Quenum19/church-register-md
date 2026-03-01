import axios from 'axios';

const API = axios.create({
  baseURL: process.env.REACT_APP_API_URL || 'http://localhost:5000/api',
});

API.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Intercepteur pour gérer l'expiration du token
API.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem('token');
      window.location.href = '/admin';
    }
    return Promise.reject(err);
  }
);

export const getStats        = ()          => API.get('/visitors/stats');
export const getVisitors     = (params)    => API.get('/visitors', { params });
export const getVisitor      = (id)        => API.get(`/visitors/${id}`);
export const updateVisitor   = (id, data)  => API.patch(`/visitors/${id}`, data);
export const convertToMember = (id)        => API.patch(`/visitors/${id}/convert`);
export const deleteVisitor   = (id)        => API.delete(`/visitors/${id}`);