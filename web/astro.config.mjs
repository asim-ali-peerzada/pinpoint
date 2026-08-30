import { defineConfig } from 'astro/config';
import react from '@astrojs/react';
import tailwind from '@astrojs/tailwind';

// https://astro.build/config
export default defineConfig({
  // Repository-scoped GitHub Pages: the site is served under /pinpoint/,
  // so all asset links must carry the base prefix (default "/" would 404
  // the CSS/JS on the live site).
  site: 'https://asim-ali-peerzada.github.io',
  base: '/pinpoint',
  integrations: [
    react(),
    tailwind({ applyBaseStyles: false }),
  ],
});
