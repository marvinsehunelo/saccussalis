import React from 'react';

const Button = ({ children, onClick, type = 'primary', disabled = false, icon: Icon }) => {
  const baseClasses = "flex items-center justify-center px-6 py-3 font-medium shadow-md border-none transition duration-200 rounded-none";
  const styles = {
    primary: `bg-yellow-500 text-gray-900 hover:bg-yellow-400`,
    secondary: `bg-gray-700 text-gray-100 hover:bg-gray-600 border border-gray-600`,
    danger: `bg-red-600 text-gray-100 hover:bg-red-500`,
  };
  return (
    <button onClick={onClick} disabled={disabled} className={`${baseClasses} ${styles[type]} ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}>
      {Icon && <Icon className="w-5 h-5 mr-2" />}
      {children}
    </button>
  );
};

export default Button;
