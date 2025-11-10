# SangelPro Theme

Tailwind CSS–powered Drupal theme that implements the SangelPro storefront layout.  
The theme ships with opinionated regions matching the provided mock-up:

1. **Utility brand** – logo/branding (top left).  
2. **Utility search** – primary search field.  
3. **Utility actions** – quick actions (wishlist, history, logout).  
4. **Hero** – large promotional banner.  
5. **Category nav** – pill navigation carousel.  
6. **Pre-content** – optional announcements.  
7. **Content** – product grid.  
8. **Sidebar** – contextual filters/promotions.  
9. **Selection panel** – saved selections / mini cart.  
10. **Footer primary / secondary** – footer sections.

## Tailwind workflow

```bash
cd web/themes/custom/sangelpro_theme
npm install
npm run dev    # watch mode
npm run build  # production build
```

The build scripts compile `assets/css/tailwind.css` to `build/css/style.css` which is referenced by the theme library.  The compiled file committed here is a placeholder; make sure to regenerate it after adjusting design tokens or components.

## Enabling the theme

```bash
drush theme:enable sangelpro_theme
drush config-set system.theme default sangelpro_theme -y
drush cr
```

## Twig templates

The main layout is defined in `templates/layout/page.html.twig`.  Additional components can be added under `templates/includes/`.

## Customisation notes

- Tailwind configuration is located in `tailwind.config.js` and exposes Sangel-specific colours, fonts, and shadow tokens.
- `sangelpro_theme.theme` adds base layout classes to the `<body>` element.
- Drop-in JS behaviors live in `assets/js/*.js`.  These are intentionally empty for now and safe to extend.

When integrating the provided mock-up, ensure that blocks/menus/views are assigned to the matching regions and that the Tailwind build process is run after any design change.
