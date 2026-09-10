import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Cutover: the SPA is the site root. base '/' makes the hashed bundles
// load from /spa/... (not /app/assets/...), and the build emits
// index.html at the docroot root next to the classic PHP console.
// Hash router (createWebHashHistory) is unchanged, so deep links stay
// /#/... and work behind plain Apache with no rewrite rules.
//
// emptyOutDir must stay FALSE: this outDir IS the live docroot and
// holds the classic PHP console, .htaccess and static assets — wiping
// it would delete the sibling PHP app. Vite only ever writes index.html
// and hashed files into spa/ here; prune orphaned bundles from old
// builds manually (htdocs/index.html + htdocs/spa/index-*.{js,css}).
export default defineConfig({
  plugins: [vue()],
  base: '/',
  build: {
    outDir: '../htdocs',
    assetsDir: 'spa',
    emptyOutDir: false
  },
  server: {
    // Dev convenience only: forward API calls to the running container.
    proxy: {
      '/cgi-bin': 'http://127.0.0.1:3385'
    }
  }
})