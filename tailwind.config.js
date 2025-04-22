/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
      "./templates/**/*.html.twig",
      "./assets/**/*.js",
      "./public/**/*.html",
    ],
    darkMode: 'class',
    theme: {
      extend: {
        fontFamily: {
          sans: ['"Comic Sans MS"', 'cursive', 'sans-serif'],
        },
      },
    },
    plugins: [],
  }
  