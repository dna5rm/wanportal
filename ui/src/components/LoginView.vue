<!--
  Sign-in for the bundled app. Posts JSON to /cgi-bin/api/login and
  keeps the returned JWT in sessionStorage (tab-scoped) via session.js;
  no CSRF token is needed because the API takes plain JSON, and the
  token itself never reaches the DOM — the session chip shows claims
  only. Sign-in is SPA-only: there is no classic login.php door here.
-->
<script setup>
import { inject, ref } from 'vue'
import { useRouter } from 'vue-router'
import { login } from '../session'

const router = useRouter()
/* App provides its session probe; re-running it right after a
 * successful sign-in is what flips the account menu on. When the
 * form is mounted outside the shell (specs, classic embeds) there is
 * nothing to inject and the redirect alone carries the update. */
const sessionProbe = inject('sessionProbe', null)

const username = ref('')
const password = ref('')
const busy = ref(false)
const error = ref('')

async function submit() {
    if (busy.value) return
    busy.value = true
    error.value = ''
    try {
        await login(username.value.trim(), password.value)
        password.value = '' // keep the secret out of the form state
        // Light the account menu before the redirect: the probe reads
        // the token login() just parked, so the chip shows the user.
        if (sessionProbe) await sessionProbe()
        router.push('/')
    } catch (err) {
        error.value = err && err.message === 'HTTP 401'
            ? 'Wrong username or password.'
            : ((err && err.message) || 'Sign-in failed.')
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="login-wrap">
        <form class="login-card" @submit.prevent="submit">
            <h2>sign in</h2>
            <p v-if="error" class="err-note block" role="alert">{{ error }}</p>
            <label class="login-label" for="login-user">username</label>
            <input id="login-user" v-model="username" class="login-input" type="text"
                   autocomplete="username" autofocus>
            <label class="login-label" for="login-pass">password</label>
            <input id="login-pass" v-model="password" class="login-input" type="password"
                   autocomplete="current-password">
            <button class="btn login-submit" type="submit" :disabled="busy">
                {{ busy ? 'signing in…' : 'sign in' }}
            </button>
        </form>
    </div>
</template>

<style scoped>
/* Dark form in the base.css palette; kept local so the shared
 * stylesheet stays dashboard-only. */
.login-wrap {
    display: flex;
    justify-content: center;
    padding: 26px 0;
}

.login-card {
    background: var(--panel);
    border: 1px solid var(--panel-edge);
    border-radius: 8px;
    padding: 18px 20px;
    width: 320px;
}

.login-card h2 {
    font-size: 12px;
    letter-spacing: .8px;
    margin: 0 0 8px;
    text-transform: uppercase;
}

.login-label {
    color: var(--muted);
    display: block;
    font-size: 11px;
    letter-spacing: .6px;
    margin: 10px 0 3px;
    text-transform: uppercase;
}

.login-input {
    background: var(--bg);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    color: var(--text);
    font: inherit;
    padding: 6px 9px;
    width: 100%;
}

.login-input:focus {
    border-color: var(--up);
    outline: none;
}

.login-submit {
    margin-top: 14px;
    width: 100%;
}

.login-submit:disabled {
    cursor: default;
    opacity: .6;
}
</style>