const defaultTheme = require("tailwindcss/defaultTheme");

module.exports = {
  content: [
    "./templates/**/*.twig",
    "./templates/**/*.html",
    "./assets/js/**/*.js",

    "./templates/layout/includes/*.twig",
    "./**/*.theme",
    "./**/*.module",
    "./**/*.php",
  ],
  theme: {
    fontSize: {
      xs: ["0.75rem", { lineHeight: "1.4" }],
      sm: ["0.875rem", { lineHeight: "1.5" }],
      base: ["1rem", { lineHeight: "1.6" }],
      lg: ["1.125rem", { lineHeight: "1.6" }],
      xl: ["1.25rem", { lineHeight: "1.45" }],
      "2xl": ["1.5rem", { lineHeight: "1.35" }],
      "3xl": ["1.75rem", { lineHeight: "1.3" }],
      "4xl": ["2rem", { lineHeight: "1.25" }],
      "5xl": ["2.5rem", { lineHeight: "1.2" }],
      "6xl": ["3rem", { lineHeight: "1.15" }],
    },
    extend: {
      colors: {
        "sangel-primary": "#1d4a9c",
        "sangel-primary-dark": "#11306d",
        "sangel-accent": "#f15b5b",
        "sangel-ice": "#f7f7f8", //"#F0F4FA",
        "sangel-ice-strong": "#d8e5f5",
        "sangel-text": "#273041",
        "sangel-air": "#0f2f55",
        "sangel-cta": "#ff4d1e",
        "sangel-cta-dark": "#e03e13",
        "auth-primary": "#1f4f8e",
        "auth-overlay": "#0c223f",
        "auth-panel": "#1a3f6d",
        "auth-accent": "#ff3c00",
        "auth-accent-dark": "#e03500",
        "auth-muted": "#1b3d68",
        "auth-input": "#14263f",
      },
      fontFamily: {
        sans: ['"Nunito Sans"', ...defaultTheme.fontFamily.sans],
      },
      boxShadow: {
        utility: "0 10px 30px -12px rgba(17, 48, 109, 0.35)",
      },
    },
  },
  plugins: [
    require("@tailwindcss/forms")({
      strategy: "class",
    }),
    require("@tailwindcss/typography"),
    require("@tailwindcss/container-queries"),
  ],
  safelist: [
    "messages",
    "messages--status",
    "messages--warning",
    "messages--error",
    "sp-auth--login",
    "sp-auth--password",
  ],
};
