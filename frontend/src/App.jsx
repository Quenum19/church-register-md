import { useState, useCallback } from 'react';
import IdentifyPage  from './pages/IdentifyPage';
import Form1Page     from './pages/Form1Page';
import Form2Page     from './pages/Form2Page';
import Form3Page     from './pages/Form3Page';
import DonePage      from './pages/DonePage';
import DashboardApp  from './DashboardApp';
import QRCodePublic  from './pages/QRCodePublic';

/* ── Flux visiteur (tous les hooks ici, jamais conditionnels) ────── */
const VisitorFlow = () => {
  const [step,       setStep]       = useState('identify');
  const [phone,      setPhone]      = useState('');
  const [visitor,    setVisitor]    = useState(null);
  const [visitCount, setVisitCount] = useState(null);

  const handleIdentified = useCallback(({ phone, nextForm, visitor }) => {
    setPhone(phone);
    setVisitor(visitor);
    setStep(`form${nextForm}`);
  }, []);

  const handleSuccess = useCallback((count, updatedVisitor) => {
    setVisitCount(count);
    if (updatedVisitor) setVisitor(updatedVisitor);
    setStep('done');
  }, []);

  const handleReset = useCallback(() => {
    setStep('identify');
    setPhone('');
    setVisitor(null);
    setVisitCount(null);
  }, []);

  return (
    <>
      {step === 'identify' && (
        <IdentifyPage onIdentified={handleIdentified} />
      )}
      {step === 'form1' && (
        <Form1Page
          phone={phone}
          onSuccess={(v) => handleSuccess(1, v)}
          onBack={handleReset}
        />
      )}
      {step === 'form2' && (
        <Form2Page
          phone={phone}
          visitor={visitor}
          onSuccess={(v) => handleSuccess(2, v)}
          onBack={handleReset}
        />
      )}
      {step === 'form3' && (
        <Form3Page
          phone={phone}
          visitor={visitor}
          onSuccess={(v) => handleSuccess(3, v)}
          onBack={handleReset}
        />
      )}
      {step === 'done' && (
        <DonePage
          visitCount={visitCount}
          visitor={visitor}
          onBack={handleReset}
        />
      )}
    </>
  );
};

/* ── App — routeur principal ─────────────────────────────────────── */
const App = () => {
  const path = window.location.pathname;

  // Routes statiques — aucun hook appelé avant ce switch
  if (path === '/qrcode')        return <QRCodePublic />;
  if (path.startsWith('/admin')) return <DashboardApp />;

  // Flux visiteur principal
  return <VisitorFlow />;
};

export default App;