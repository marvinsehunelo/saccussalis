import React, { useState } from 'react';
import { LogIn, Loader2 } from 'lucide-react';
import { apiFetch } from '../services/api';

const LoginPage = ({ setRoute }) => {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const handleLogin = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const data = await apiFetch('/auth/login.php', {
        method: 'POST',
        body: JSON.stringify({ username, password }),
      });

      localStorage.setItem('authToken', data.token);
      localStorage.setItem('role', data.role);
      localStorage.setItem('username', data.username);
      setRoute('dashboard');
    } catch (err) {
      setError(err.message || 'Login failed.');
      localStorage.removeItem('authToken');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex items-center justify-center min-h-screen bg-gray-900 p-4">
      <form onSubmit={handleLogin} className="bg-gray-800 p-8 shadow-xl w-full max-w-md border-t-4 border-yellow-500">
        <h2 className="text-3xl text-gray-100 mb-6 flex items-center">
          <LogIn className="w-7 h-7 mr-3 text-yellow-500" />
          Secure Access
        </h2>
        {error && <div className="bg-red-900 text-red-300 p-3 mb-4">{error}</div>}
        <input type="email" placeholder="Email" value={username} onChange={(e)=>setUsername(e.target.value)} className="w-full mb-4 p-2 bg-gray-900 text-gray-100 border border-gray-700"/>
        <input type="password" placeholder="Password" value={password} onChange={(e)=>setPassword(e.target.value)} className="w-full mb-4 p-2 bg-gray-900 text-gray-100 border border-gray-700"/>
        <button type="submit" disabled={loading} className="w-full py-3 bg-yellow-500 text-black flex justify-center items-center">
          {loading ? <Loader2 className="w-5 h-5 animate-spin mr-2" /> : <LogIn className="w-5 h-5 mr-2"/>}
          {loading ? 'Authenticating...' : 'Sign In'}
        </button>
      </form>
    </div>
  );
};

export default LoginPage;
