import React, { useState, useEffect } from 'react';
import Loader from '../components/Loader';
import IconHeader from '../components/IconHeader';
import Button from '../components/Button';
import { Wallet, DollarSign, Activity } from 'lucide-react';

const WalletPage = () => {
  const [balance, setBalance] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const fetchBalance = async () => {
      setLoading(true);
      try {
        const res = await fetch('/backend/wallet/balance.php', {
          headers: { Authorization: `Bearer ${localStorage.getItem('authToken')}` }
        });
        const result = await res.json();
        setBalance(result.walletBalance);
      } catch (err) {
        setError(err.message);
      } finally {
        setLoading(false);
      }
    };
    fetchBalance();
  }, []);

  if (loading) return <Loader message="Loading wallet..." />;
  if (error) return <div className="p-8 text-red-400 text-center bg-gray-900 border border-red-600 m-4">{error}</div>;

  return (
    <div className="p-4 sm:p-8">
      <IconHeader icon={Wallet} title="FNB-Style eWallet" subtitle={`Balance: R ${balance?.toFixed(2) || '0.00'}`} />

      <div className="max-w-xl mx-auto bg-gray-800 shadow-2xl p-8 sm:p-10 text-center border-t-8 border-yellow-500 border-b-8 rounded-none">
        <div className="mt-8 flex justify-center space-x-4">
          <Button type="primary" icon={DollarSign}>Send Money</Button>
          <Button type="secondary" icon={Activity}>View Transactions</Button>
        </div>
        <p className="text-sm text-gray-500 mt-6">Powered by PHP Wallet API</p>
      </div>
    </div>
  );
};

export default WalletPage;
