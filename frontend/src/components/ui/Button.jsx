const Button = ({ children, onClick, disabled, variant = 'gold', type = 'button', className = '' }) => {
  if (variant === 'outline') {
    return (
      <button
        type={type}
        onClick={onClick}
        disabled={disabled}
        className={`btn-outline ${className}`}
      >
        {children}
      </button>
    );
  }

  if (variant === 'ghost') {
    return (
      <button
        type={type}
        onClick={onClick}
        disabled={disabled}
        className={`text-church-gold font-body font-semibold text-sm hover:text-church-gold-lt
                    transition-colors duration-200 ${className}`}
      >
        {children}
      </button>
    );
  }

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={`btn-gold w-full text-center ${className}`}
    >
      {disabled ? (
        <span className="flex items-center justify-center gap-2">
          <svg className="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          Chargement…
        </span>
      ) : children}
    </button>
  );
};

export default Button;