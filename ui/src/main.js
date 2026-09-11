import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import './styles/base.css'
import { applyStoredTheme } from './theme'

/* Restore the persisted light/dark choice before the shell mounts so
 * the first paint already carries it — no dark flash for light-theme
 * visitors. The key is the classic console's ('wanportal-theme', see
 * htdocs/classic/footer.php), so the two consoles share one preference. */
applyStoredTheme()

createApp(App).use(router).mount('#app')