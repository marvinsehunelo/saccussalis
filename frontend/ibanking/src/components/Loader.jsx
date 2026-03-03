import React from 'react';
import { Loader2 } from 'lucide-react';

const Loader = ({ message = "Loading...", size = 8 }) => (
  <div className="flex flex-col items-center justify-center h-64">
    <Loader2 className={`w-${size} h-${size} text-yellow-500 animate-spin`} />
    <span className="ml-0 mt-3 text-lg font-medium text-gray-400">{message}</span>
  </div>
);

export default Loader;
