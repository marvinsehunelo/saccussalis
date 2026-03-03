import React from 'react';

const IconHeader = ({ icon: Icon, title, subtitle }) => (
  <div className="flex items-center mb-6">
    {Icon && <Icon className="w-8 h-8 text-yellow-500 mr-3" />}
    <div>
      <h1 className="text-4xl font-bold text-yellow-500">{title}</h1>
      {subtitle && <p className="text-gray-400 text-lg">{subtitle}</p>}
    </div>
  </div>
);

export default IconHeader;
