/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./src/**/*.{js,jsx,ts,tsx}"],
  theme: {
    extend: {
      colors: {
        church: {
          purple:      '#6B1F8A',
          'purple-dk': '#4A0E6B',
          'purple-lg': '#8B35AA',
          'purple-xl': '#F3E8FF',
          gold:        '#C9A227',
          'gold-lt':   '#E8C547',
          'gold-dk':   '#9B7A10',
          'gold-pale':  '#FBF3DC',
        },
      },
      fontFamily: {
        display: ['Playfair Display', 'Georgia', 'serif'],
        body:    ['Lato', 'Helvetica Neue', 'sans-serif'],
      },
      keyframes: {
        fadeUp: {
          '0%':   { opacity: '0', transform: 'translateY(24px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        shimmer: {
          '0%, 100%': { backgroundPosition: '0% 50%' },
          '50%':      { backgroundPosition: '100% 50%' },
        },
        glow: {
          '0%, 100%': { boxShadow: '0 0 20px rgba(201,162,39,0.3)' },
          '50%':      { boxShadow: '0 0 40px rgba(201,162,39,0.7)' },
        },
        spin_slow: {
          from: { transform: 'rotate(0deg)' },
          to:   { transform: 'rotate(360deg)' },
        },
        pulse_gold: {
          '0%, 100%': { opacity: '1' },
          '50%':      { opacity: '0.6' },
        },
      },
      animation: {
        'fade-up':    'fadeUp 0.6s ease-out forwards',
        'fade-up-d1': 'fadeUp 0.6s ease-out 0.1s forwards',
        'fade-up-d2': 'fadeUp 0.6s ease-out 0.2s forwards',
        'fade-up-d3': 'fadeUp 0.6s ease-out 0.3s forwards',
        'fade-up-d4': 'fadeUp 0.6s ease-out 0.4s forwards',
        'shimmer':    'shimmer 4s ease infinite',
        'glow':       'glow 2s ease-in-out infinite',
        'pulse-gold': 'pulse_gold 2s ease-in-out infinite',
      },
      backgroundImage: {
        'church-gradient': 'linear-gradient(135deg, #4A0E6B 0%, #6B1F8A 40%, #3D0A5A 100%)',
        'gold-gradient':   'linear-gradient(135deg, #9B7A10 0%, #C9A227 40%, #E8C547 60%, #C9A227 100%)',
        'card-gradient':   'linear-gradient(180deg, rgba(255,255,255,0.98) 0%, rgba(251,243,220,0.5) 100%)',
      },
      boxShadow: {
        'gold':   '0 4px 24px rgba(201,162,39,0.35)',
        'purple': '0 8px 32px rgba(74,14,107,0.4)',
        'card':   '0 20px 60px rgba(74,14,107,0.15)',
      },
    },
  },
  plugins: [],
};