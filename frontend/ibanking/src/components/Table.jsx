import React from 'react';

const Table = ({ columns, data }) => {
  return (
    <div className="bg-gray-800 border border-gray-700 shadow-xl overflow-x-auto rounded-none">
      <table className="min-w-full divide-y divide-gray-700">
        <thead className="bg-gray-700">
          <tr>
            {columns.map(col => (
              <th key={col} className="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">{col}</th>
            ))}
          </tr>
        </thead>
        <tbody className="bg-gray-800 divide-y divide-gray-700">
          {data.map((row, index) => (
            <tr key={index} className="hover:bg-gray-700/50">
              {Object.values(row).map((cell, i) => (
                <td key={i} className="px-6 py-4 whitespace-nowrap text-sm text-gray-200">{cell}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

export default Table;
