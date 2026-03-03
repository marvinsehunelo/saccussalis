import React from 'react';
import IconHeader from '../components/IconHeader';
import { UserCog } from 'lucide-react';

const AdminPage = () => {
  const role = localStorage.getItem('role');

  if (role !== 'admin') {
    return (
      <div className="p-8 text-red-400 text-center bg-gray-900 border border-red-600 m-4 max-w-lg mx-auto">
        <h1 className="text-2xl font-bold mb-4">ACCESS DENIED</h1>
        <p>You must be an Administrator to view this page. Current role: <span className="font-semibold">{role}</span></p>
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-8">
      <IconHeader icon={UserCog} title="Administrator Panel" />
      <div className="bg-gray-800 border border-gray-700 shadow-xl p-6 border-l-4 border-red-500 rounded-none">
        <h2 className="text-2xl font-semibold text-gray-100 mb-4">Admin Actions</h2>
        <ul className="space-y-3">
          <li className="p-3 bg-gray-900 border border-gray-700 hover:bg-gray-700/70 cursor-pointer transition flex justify-between items-center text-gray-300">
            User Management <span className="text-sm text-yellow-500">POST /backend/admin/user_mgmt.php</span>
          </li>
          <li className="p-3 bg-gray-900 border border-gray-700 hover:bg-gray-700/70 cursor-pointer transition flex justify-between items-center text-gray-300">
            Generate Reports <span className="text-sm text-yellow-500">GET /backend/admin/reports.php</span>
          </li>
        </ul>
      </div>
    </div>
  );
};

export default AdminPage;
