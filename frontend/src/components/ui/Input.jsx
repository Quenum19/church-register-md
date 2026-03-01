const Input = ({ label, type = 'text', placeholder, value, onChange, onKeyDown, required, error }) => {
  return (
    <div className="flex flex-col gap-1.5">
      {label && (
        <label className="text-xs font-body font-bold text-church-purple uppercase tracking-widest">
          {label}
          {required && <span className="text-church-gold ml-1">*</span>}
        </label>
      )}
      <input
        type={type}
        placeholder={placeholder}
        value={value}
        onChange={onChange}
        onKeyDown={onKeyDown}
        className={`church-input ${error ? 'border-red-400 focus:border-red-400' : ''}`}
      />
      {error && (
        <p className="text-xs text-red-500 font-body">{error}</p>
      )}
    </div>
  );
};

export default Input;