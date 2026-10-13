/** @type {import('tailwindcss').Config} */
// Charte reprise de l'appli formateur SuiviCours (identité Alag-info) :
// marine pour la structure, orange brûlé réservé à l'action principale et à l'élément actif.
export default {
  content: ['./index.html', './src/**/*.{vue,js}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Inter Variable"', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        display: ['"Bricolage Grotesque Variable"', '"Inter Variable"', 'ui-sans-serif', 'system-ui', 'sans-serif']
      },
      colors: {
        'alag-blue': { DEFAULT: '#0080FF', dark: '#0066CC', mid: '#3399FF', light: '#E6F2FF' },
        'alag-navy': { DEFAULT: '#1F4C73', dark: '#0F3960', light: '#39668D', deep: '#0B2A47' },
        accent: { DEFAULT: '#C2410C', hover: '#9A3412', soft: '#FFEDD5' },
        paper: '#F6F5F1'
      },
      transitionDuration: { DEFAULT: '180ms' }
    }
  }
}
