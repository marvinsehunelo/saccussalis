import React, { useState, useEffect } from 'react';
import Loader from '../components/Loader';
import Table from '../components/Table';
import IconHeader from '../components/IconHeader';
import { DollarSign } from 'lucide-react';

const AccountsPage = () => {
  const [accounts, setAccounts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const fetchAccounts = async () => {
      setLoading(true);
      try {
        const res = await fetch('/backend/accounts/list.php', {
          headers: { Authorization: `Bearer ${localStorage.getItem('authToken')}` }
        });
        const data = await res.json();
        setAccounts(data.accounts);
      } catch (err) {
        setError(err.message);
      } finally {
        setLoading(false);
      }
    };
    fetchAccounts();
  }, []);

  if (loading) return <Loader message="Loading accounts..." />;
  if (error) return <div className="p-8 text-red-400 text-center bg-gray-900 border border-red-600 m-4">{error}</div>;

  return (
    <div className="p-4 sm:p-8">
      <IconHeader icon={DollarSign} title="Your Accounts" />
      <Table 
        columns={['Account Number', 'Type', 'Balance', 'Status']} 
        data={accounts.map(a => ({
          'Account Number': a.accountNumber,
          'Type': a.type,
          'Balance': `R ${a.balance.toFixed(2)}`,
          'Status': a.status
        }))} 
      />
    </div>
  );
};

export default AccountsPage;
