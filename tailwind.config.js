/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./public_html/**/*.php",
    "./public_html/**/*.html",
    "./public_html/**/*.js"
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#f0f3ff',
          100: '#e1e8ff',
          200: '#c7d6ff',
          300: '#9db4ff',
          400: '#6b86ff',
          500: '#3b52ff',
          600: '#2534f5',
          700: '#1c22e0',
          800: '#181cb6',
          900: '#191f90',
          950: '#101157',
        }
      },
      fontFamily: {
        sans: ['Fira Sans', 'sans-serif'],
        mono: ['Fira Code', 'monospace'],
      }
    },
  },
  plugins: [],
}
