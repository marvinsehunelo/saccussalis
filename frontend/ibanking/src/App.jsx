import React, { useState, useEffect, useCallback, useMemo } from 'react';
import Header from './components/Header';
import DashboardPage from './pages/DashboardPage';
import WalletPage from './pages/WalletPage';
import AccountsPage from './pages/AccountsPage';
import TransactionsPage from './pages/TransactionsPage';
import AdminPage from './pages/AdminPage';
import LoginPage from './pages/LoginPage';

const App = () => {
  // --- Routing ---
  const getInitialRoute = () => window.location.hash.slice(2) || 'dashboard';
  const [route, setRoute] = useState(getInitialRoute);

  // --- Authentication State ---
  const [isAuthenticated, setIsAuthenticated] = useState(!!localStorage.getItem('authToken'));
  const [role, setRole] = useState(localStorage.getItem('role') || 'user');

  // --- Sync hash URL with route ---
  useEffect(() => {
    const handleHashChange = () => setRoute(window.location.hash.slice(2) || 'dashboard');
    window.addEventListener('hashchange', handleHashChange);
    return () => window.removeEventListener('hashchange', handleHashChange);
  }, []);

  useEffect(() => {
    window.location.hash = `/${route}`;
    if (!isAuthenticated && route !== 'login') setRoute('login');
  }, [route, isAuthenticated]);

  // --- Logout Handler ---
  const handleLogout = useCallback(() => {
    localStorage.removeItem('authToken');
    localStorage.removeItem('role');
    setIsAuthenticated(false);
    setRole('user');
    setRoute('login');
  }, []);

  // --- Render Pages ---
  const renderPage = useMemo(() => {
    if (!isAuthenticated) {
      return (
        <LoginPage 
          setRoute={setRoute} 
          setAuth={setIsAuthenticated} 
          setRole={setRole} 
        />
      );
    }

    switch(route) {
      case 'dashboard': return <DashboardPage />;
      case 'wallet': return <WalletPage />;
      case 'accounts': return <AccountsPage />;
      case 'transactions': return <TransactionsPage />;
      case 'admin': return <AdminPage />;
      case 'login': return <DashboardPage />; // fallback
      default: return <div className="p-8 text-gray-100 text-center">404 Page Not Found</div>;
    }
  }, [route, isAuthenticated, role]);

  return (
    <div className="min-h-screen bg-gray-950 font-sans">
      <script src="https://cdn.tailwindcss.com"></script>
      <Header 
        route={route} 
        setRoute={setRoute} 
        isAuthenticated={isAuthenticated} 
        role={role} 
        handleLogout={handleLogout} 
      />
      <main className="max-w-7xl mx-auto">
        {renderPage}
      </main>
    </div>
  );
};

export default App;
