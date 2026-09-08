import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Everything ships as same-origin bundles under /app/ — no CDN scripts.
// The build lands directly in the Apache docroot so the container picks
// it up on the next request, no copying step.
export default defineConfig({
  plugins: [vue()],
  base: '/app/',
  build: {
    outDir: '../htdocs/app',
    emptyOutDir: true
  },
  server: {
    // Dev convenience only: forward API calls to the running container.
    proxy: {
      '/cgi-bin': 'http://127.0.0.1:3385'
    }
  }
})