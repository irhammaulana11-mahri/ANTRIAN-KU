/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: { ink: '#102a43', brand: '#0f766e', accent: '#f59e0b', surface: '#f7fafc' },
      boxShadow: { soft: '0 10px 35px rgba(16, 42, 67, .08)' },
    },
  },
  plugins: [require('@tailwindcss/forms')],
}
