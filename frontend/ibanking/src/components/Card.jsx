import React from 'react';

const Card = ({ title, value, icon: Icon, color = 'yellow', accentColor = 'yellow' }) => {
  return (
    <div className={`bg-gray-800 p-6 shadow-lg border border-gray-700 border-l-4 border-${accentColor}-500 transition-all duration-300 hover:border-${accentColor}-400 rounded-none`}>
      <div className="flex items-center justify-between">
        <p className="text-sm font-medium text-gray-400">{title}</p>
        {Icon && <Icon className={`w-6 h-6 text-${color}-500`} />}
      </div>
      <p className="mt-1 text-3xl font-extrabold text-gray-100">{value}</p>
    </div>
  );
};

export default Card;
