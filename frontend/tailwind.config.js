/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: { ink: '#102a43', brand: '#0f766e', accent: '#f59e0b', surface: '#f7fafc' },
      boxShadow: { soft: '0 10px 35px rgba(16, 42, 67, .08)' },
      keyframes: { 'slide-in': { from: { opacity: '0', transform: 'translateX(calc(100% + 1.5rem))' }, to: { opacity: '1', transform: 'translateX(0)' } } },
      animation: { 'slide-in': 'slide-in 0.22s cubic-bezier(0.16,1,0.3,1) both' },
    },
  },
  plugins: [require('@tailwindcss/forms')],
}
