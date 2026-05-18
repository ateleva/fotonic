/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  corePlugins: { preflight: false },
  important: '#fotonic-app-root',
  theme: {
    extend: {
      colors: {
        'fotonic-primary': 'var(--fotonic-primary)',
      },
    },
  },
  plugins: [],
}
