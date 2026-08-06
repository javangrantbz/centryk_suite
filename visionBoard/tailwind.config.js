/**
 * Tailwind config for the Belize Zoo admin panel.
 *
 * To rebuild the compiled stylesheet after changing admin templates, run the
 * Tailwind standalone CLI from the project root:
 *
 *   tailwindcss -c tailwind.config.js -i assets/css/tailwind.input.css \
 *               -o assets/css/tailwind.css --minify
 *
 * (See build-css.bat for a one-click version on Windows.)
 */
module.exports = {
  content: [
    './*.php',
    './admin/**/*.php',
    './includes/**/*.php',
    './display/**/*.php',
  ],
  theme: {
    extend: {
      colors: {
        jungle: {
          50:  '#f1f9f0',
          100: '#dcefd9',
          500: '#2f9e44',
          600: '#237a33',
          700: '#1b5e28',
          900: '#0f3d1a',
        },
      },
    },
  },
  plugins: [],
};
