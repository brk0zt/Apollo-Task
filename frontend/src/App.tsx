import React, { useState } from 'react';
import { useAuth } from './context/AuthContext';
import LoginForm from './components/auth/LoginForm';
import RegisterForm from './components/auth/RegisterForm';
import Dashboard from './pages/Dashboard';

const App: React.FC = () => {
  const { isAuthenticated, isLoading } = useAuth();
  const [currentView, setCurrentView] = useState<'login' | 'register'>('login');

  if (isLoading) {
    return (
      <div className="min-h-screen bg-slate-950 flex items-center justify-center">
        <div className="flex flex-col items-center space-y-4">
          <div className="w-12 h-12 border-4 border-amber-500 border-t-transparent rounded-full animate-spin"></div>
          <p className="text-white/60 text-sm">Loading Apollo Systems...</p>
        </div>
      </div>
    );
  }

  if (isAuthenticated) {
    return <Dashboard />;
  }

  return (
    <div className="min-h-screen bg-slate-950 flex items-center justify-center p-4">
      {currentView === 'login' ? (
        <LoginForm
          onSuccess={() => {}}
          onNavigateToRegister={() => setCurrentView('register')}
        />
      ) : (
        <RegisterForm
          onSuccess={() => {}}
          onNavigateToLogin={() => setCurrentView('login')}
        />
      )}
    </div>
  );
};

export default App;
