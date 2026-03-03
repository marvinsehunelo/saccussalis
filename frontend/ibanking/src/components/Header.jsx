import React from 'react';
import { LayoutDashboard, Wallet, UserCog, LogIn, LogOut } from 'lucide-react';
import Button from './Button';

const Header = ({ route, setRoute, isAuthenticated, role, handleLogout }) => {

  const NavItem = ({ name, icon: Icon, path, onClick }) => (
    <button
      onClick={onClick}
      className={`flex items-center px-3 py-2 text-sm font-medium transition duration-150 border-b-2 ${
        route === path
          ? 'bg-gray-700 text-yellow-500 border-yellow-500'
          : 'text-gray-300 hover:bg-gray-700 hover:text-gray-100 border-transparent'
      } rounded-none`}
    >
      <Icon className="w-5 h-5 mr-2" />
      {name}
    </button>
  );

  return (
    <header className="bg-gray-900 border-b border-gray-700 sticky top-0 z-10">
      <div className="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex justify-between h-16 items-center">
          <div 
            className="text-yellow-500 font-black text-2xl cursor-pointer tracking-widest"
            onClick={() => setRoute('dashboard')}
          >
            SACCUSSALIS
          </div>

          {isAuthenticated ? (
            <nav className="flex space-x-2">
              <NavItem name="Dashboard" icon={LayoutDashboard} path="dashboard" onClick={() => setRoute('dashboard')} />
              <NavItem name="Wallet" icon={Wallet} path="wallet" onClick={() => setRoute('wallet')} />
              {role === 'admin' && <NavItem name="Admin" icon={UserCog} path="admin" onClick={() => setRoute('admin')} />}
              <NavItem name="Logout" icon={LogOut} path="login" onClick={handleLogout} />
            </nav>
          ) : (
            <Button type="primary" icon={LogIn} onClick={() => setRoute('login')}>Login</Button>
          )}
        </div>
      </div>
    </header>
  );
};

export default Header;
