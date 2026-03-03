import React, { useState, useEffect } from 'react';
import Card from '../components/Card';
import Table from '../components/Table';
import Loader from '../components/Loader';
import IconHeader from '../components/IconHeader';
import { DollarSign, Activity, Users, LogIn } from 'lucide-react';

const DashboardPage = () => {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const role = localStorage.getItem('role');

  useEffect(() => {
    const fetchDashboard = async () => {
      setLoading(true);
      try {
        const res = await fetch('/backend/accounts/dashboard.php', {
          headers: { Authorization: `Bearer ${localStorage.getItem('authToken')}` }
        });
        const result = await res.json();
        setData(result);
        setError(null);
      } catch (err) {
        setError(err.message);
      } finally {
        setLoading(false);
      }
    };
    fetchDashboard();
  }, []);

  if (loading) return <Loader message="Loading dashboard..." />;
  if (error) return <div className="p-8 text-red-400 text-center bg-gray-900 border border-red-600 m-4">{error}</div>;

  return (
    <div className="p-4 sm:p-8">
      <IconHeader title={`Welcome, ${data?.username || 'Client'}`} subtitle={`Your role: ${role}`} />
      
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
        <Card icon={DollarSign} title="Total Balance" value={`R ${data?.totalBalance.toFixed(2)}`} color="green" accentColor="green" />
        <Card icon={Activity} title="YTD Transactions" value={data?.transactionCount} color="blue" accentColor="blue" />
        <Card icon={Users} title="Beneficiaries" value={data?.beneficiaries} color="purple" accentColor="purple" />
        <Card icon={LogIn} title="Last Login" value={data?.lastLogin} color="yellow" accentColor="yellow" />
      </div>

      <h2 className="text-3xl font-semibold text-gray-100 mt-12 mb-4">Recent Activity</h2>
      <Table 
        columns={['Date', 'Description', 'Type', 'Amount', 'Balance']} 
        data={data?.transactions.slice(0, 5).map(t => ({
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

export default DashboardPage;
