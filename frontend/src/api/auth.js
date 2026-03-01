import axios from 'axios';

const API = axios.create({
  baseURL: process.env.REACT_APP_API_URL || 'http://localhost:5000/api',
});

API.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

export const login          = (data) => API.post('/auth/login', data);
export const getMe          = ()     => API.get('/auth/me');
export const changePassword = (data) => API.patch('/auth/change-password', data);

export const getAdmins   = ()      => API.get('/auth/admins');
export const createAdmin = (data)  => API.post('/auth/register', data);
export const updateAdmin = (id, d) => API.patch(`/auth/admins/${id}`, d);
export const deleteAdmin = (id)    => API.delete(`/auth/admins/${id}`);

export default API;