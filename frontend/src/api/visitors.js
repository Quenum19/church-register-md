import axios from 'axios';

const API = axios.create({
  baseURL: process.env.REACT_APP_API_URL || 'http://localhost:5000/api',
});

export const identifyVisitor  = (phone) => API.post('/visitors/identify', { phone });
export const submitVisit1     = (data)  => API.post('/visitors/visit1', data);
export const submitVisit2     = (data)  => API.post('/visitors/visit2', data);
export const submitVisit3     = (data)  => API.post('/visitors/visit3', data);