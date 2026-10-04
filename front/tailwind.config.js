/** @type {import('tailwindcss').Config} */
// Même palette que l'appli formateur SuiviCours (identité Alag-info).
export default {
  content: ['./index.html', './src/**/*.{vue,js}'],
  theme: {
    extend: {
      colors: {
        'alag-blue': { DEFAULT: '#0080FF', dark: '#0066CC', mid: '#3399FF', light: '#E6F2FF' },
        'alag-navy': { DEFAULT: '#1F4C73', dark: '#0F3960', light: '#39668D' }
      }
    }
  }
}
