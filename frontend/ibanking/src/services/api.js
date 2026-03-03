const API_BASE_URL = '/backend';

export const apiFetch = async (endpoint, options={}) => {
  const token = localStorage.getItem('authToken');
  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
  if(token) headers['Authorization'] = `Bearer ${token}`;
  
  const res = await fetch(`${API_BASE_URL}${endpoint}`, {...options, headers});
  if(!res.ok) throw new Error(await res.text());
  return res.json();
};
