import React, { useState, useEffect } from 'react';
import Loader from '../components/Loader';
import Table from '../components/Table';
import IconHeader from '../components/IconHeader';
import { Activity } from 'lucide-react';

const TransactionsPage = () => {
  const [transactions, setTransactions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const fetchTransactions = async () => {
      setLoading(true);
      try {
        const res = await fetch('/backend/transactions/list.php', {
          headers: { Authorization: `Bearer ${localStorage.getItem('authToken')}` }
        });
        const data = await res.json();
        setTransactions(data.transactions);
      } catch (err) {
        setError(err.message);
      } finally {
        setLoading(false);
      }
    };
    fetchTransactions();
  }, []);

  if (loading) return <Loader message="Loading transactions..." />;
  if (error) return <div className="p-8 text-red-400 text-center bg-gray-900 border border-red-600 m-4">{error}</div>;

  return (
    <div className="p-4 sm:p-8">
      <IconHeader icon={Activity} title="Recent Transactions" />
      <Table 
        columns={['Date', 'Description', 'Type', 'Amount', 'Balance']} 
        data={transactions.map(t => ({
          Date: t.date,
          Description: t.description,
          Type: t.type,
          Amount: `R ${t.amount.toFixed(2)}`,
          Balance: `R ${t.balance.toFixed(2)}`
        }))} 
      />
    </div>
  );
};

export default TransactionsPage;
