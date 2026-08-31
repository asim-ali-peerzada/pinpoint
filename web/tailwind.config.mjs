/** @type {import('tailwindcss').Config} */
export default {
  content: ['./src/**/*.{astro,html,js,jsx,md,mdx,svelte,ts,tsx,vue}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        brand: {
          bg: '#08090B',
          card: '#0D0F12',
          'card-hover': '#121417',
          border: '#22252A',
          'border-active': '#2F3339',
          accent: 'rgb(245 48 3 / <alpha-value>)',
          'accent-glow': 'rgba(245, 48, 3, 0.15)',
          muted: '#CBD5E1', // Upgraded to Slate-300 for 11.5:1 WCAG AAA contrast
          fg: '#F8FAFC',
          success: '#3FB950',
          warning: '#D29922',
        }
      },
      fontFamily: {
        sans: ['"DM Sans"', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        display: ['"Plus Jakarta Sans"', 'Inter', 'sans-serif'],
        mono: ['JetBrains Mono', 'ui-monospace', 'monospace'],
        instrument: ['"Instrument Sans"', 'InstrumentSans', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      animation: {
        'pulse-slow': 'pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite',
        'mesh': 'mesh 15s ease infinite alternate',
      },
      keyframes: {
        mesh: {
          '0%': { transform: 'translate(0px, 0px) scale(1)' },
          '50%': { transform: 'translate(30px, -20px) scale(1.05)' },
          '100%': { transform: 'translate(-20px, 15px) scale(0.95)' },
        }
      }
    },
  },
  plugins: [],
};
