/*
 * Vitest setup for the wanportal UI. Tests run in jsdom so the Vue
 * components can mount like they would in a real browser, and the vue
 * plugin handles single-file components. This config is test-only —
 * the production build config lives in vite.config.js untouched.
 */
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['src/**/*.spec.js'],
        // Guard against a forgotten timer or fetch mock keeping the
        // worker alive; every test file unmounts what it mounts.
        pool: 'forks',
        restoreMocks: true
    }
})