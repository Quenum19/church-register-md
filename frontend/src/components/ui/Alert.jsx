const STYLES = {
  error:   { bg: 'bg-red-50 border-red-200',           text: 'text-red-700',         icon: '✕' },
  success: { bg: 'bg-green-50 border-green-200',        text: 'text-green-700',       icon: '✓' },
  warning: { bg: 'bg-church-gold-pale border-church-gold/30', text: 'text-church-gold-dk', icon: '⚠' },
  info:    { bg: 'bg-church-purple-xl border-church-purple/20', text: 'text-church-purple', icon: 'ℹ' },
};

const Alert = ({ type = 'info', message }) => {
  const s = STYLES[type] || STYLES.info;
  return (
    <div className={`flex items-start gap-3 px-4 py-3 rounded-xl border text-sm font-body ${s.bg} ${s.text}`}>
      <span className="font-bold text-base leading-5 shrink-0">{s.icon}</span>
      <p>{message}</p>
    </div>
  );
};

export default Alert;