/*
 * Router for the bundled app. Hash history keeps every link working
 * behind plain Apache — no rewrite rules for deep paths, nothing to
 * remember on the server. Every page in the nav renders in the app
 * now; sign-in moved into the app too as the /login route, and the
 * work that still belongs to the classic console (edits) is linked
 * out to it rather than stubbed here.
 *
 * Route lines carry route markers as the agreed anchor points: new
 * pages get added next to their marker as small patches, the table is
 * not rewritten wholesale. Listing routes carry their name and legacy
 * page in meta so detail pages can link back without knowing which
 * entity they belong to.
 */

import { createRouter, createWebHashHistory } from 'vue-router'
import DashboardView from './components/DashboardView.vue'
import MonitorsView from './components/MonitorsView.vue'
import AgentsView from './components/AgentsView.vue'
import TargetsView from './components/TargetsView.vue'
import SearchView from './components/SearchView.vue'
import LatencyView from './components/LatencyView.vue'
import CredentialsView from './components/CredentialsView.vue'
import UsersView from './components/UsersView.vue'
import UserEditView from './components/UserEditView.vue'
import MonitorDetailView from './components/MonitorDetailView.vue'
import MonitorEditView from './components/MonitorEditView.vue'
import AgentDetailView from './components/AgentDetailView.vue'
import AgentEditView from './components/AgentEditView.vue'
import NetpingView from './components/NetpingView.vue'
import TargetDetailView from './components/TargetDetailView.vue'
import TargetEditView from './components/TargetEditView.vue'
import CredentialDetailView from './components/CredentialDetailView.vue'
import CredentialEditView from './components/CredentialEditView.vue'
import ServerView from './components/ServerView.vue'
import ApiDocsView from './components/ApiDocsView.vue'
import LoginView from './components/LoginView.vue'
import { getSession } from './session'

/*
 * Detail pages take the record id as a prop and keep their list route
 * in meta, so the back-to-list links stay one-liners. The canonical
 * path rides the plural listing (/monitors/:id) and the singular form
 * stays as an alias because detailShared.detailLink still builds
 * '#/monitor/<uuid>' hrefs; drop the alias once that builder is
 * updated.
 *
 * auth flags a detail route as sign-in only (credential: the classic
 * credential_view.php sits behind check_session.php). Leave it off for
 * the public drill-down details — the dashboard links monitor/agent/
 * target records for visitors who never sign in. The opts object keeps
 * call sites from ever passing a bare undefined in alias's slot.
 */
function detail(path, name, component, legacy, list, opts = {}) {
    const route = {
        path,
        name,
        component,
        props: true,
        meta: { legacy, list }
    }
    // vue-router iterates alias; undefined throws "aliases is not iterable"
    // and the whole app mounts nothing (blank page).
    if (opts.alias) route.alias = opts.alias
    if (opts.auth) route.meta.auth = true
    return route
}

const routes = [
    { path: '/', name: 'dashboard', component: DashboardView },
    /* route:monitors */ { path: '/monitors', name: 'monitors', component: MonitorsView, meta: { auth: true } },
    /* route:agents */ { path: '/agents', name: 'agents', component: AgentsView, meta: { auth: true } },
    /* route:targets */ { path: '/targets', name: 'targets', component: TargetsView, meta: { auth: true } },
    /* route:users */
    {
        // Admin-gated listing backed by GET /cgi-bin/api/users; the
        // view itself shows "admin only" to everyone else. Create and
        // edit live in the app on the routes just below.
        name: 'users',
        path: '/users',
        component: UsersView,
        meta: { auth: true }
    },
    /* route:user-edit */
    {
        // User create/edit lives in the app now, one component for
        // both doors: /users/new carries no id (create, POST) and
        // /users/:id/edit passes the record id as a prop (load, then
        // PUT). Keep them above any future /users/:id detail route so
        // the static "new" segment is never read as an id.
        name: 'user-new',
        path: '/users/new',
        component: UserEditView,
        meta: { auth: true }
    },
    {
        name: 'user-edit',
        path: '/users/:id/edit',
        component: UserEditView,
        props: true,
        meta: { auth: true }
    },
    /* route:monitor-new */ { path: '/monitors/new', name: 'monitor-new', component: MonitorEditView, meta: { auth: true } },
    /* route:monitor-edit */ { path: '/monitors/:id/edit', name: 'monitor-edit', component: MonitorEditView, props: true, meta: { auth: true } },
    /* route:monitor */ detail('/monitors/:id', 'monitor', MonitorDetailView, '/monitor.php', 'monitors', { alias: '/monitor/:id' }),
    /* route:agent-new */
    {
        // Agent create/edit lives in the app now, one component for
        // both doors: /agents/new carries no id (create, POST) and
        // /agents/:id/edit passes the record id as a prop (load from
        // the public detail endpoint, then PUT). Both sit above the
        // /agents/:id detail route so the static "new" segment is
        // never read as an id.
        name: 'agent-new',
        path: '/agents/new',
        component: AgentEditView,
        meta: { auth: true }
    },
    {
        name: 'agent-edit',
        path: '/agents/:id/edit',
        component: AgentEditView,
        props: true,
        meta: { auth: true }
    },
    /* route:agent */ detail('/agents/:id', 'agent', AgentDetailView, '/agent.php', 'agents', { alias: '/agent/:id' }),
    /* route:agent-netping */
    {
        // Install page for one agent: explains the docker image and
        // run command, and links the classic /netping.php?id=<uuid>
        // for the script itself — serving the perl stays with the
        // classic console, not the bundle.
        name: 'agent-netping',
        path: '/agents/:id/netping',
        component: NetpingView,
        props: true
    },
    /* route:target-new */
    {
        name: 'target-new',
        path: '/targets/new',
        component: TargetEditView,
        meta: { auth: true }
    },
    {
        name: 'target-edit',
        path: '/targets/:id/edit',
        component: TargetEditView,
        props: true,
        meta: { auth: true }
    },
    /* route:target */ detail('/targets/:id', 'target', TargetDetailView, '/target.php', 'targets', { alias: '/target/:id' }),
    /* route:search */ { path: '/search', name: 'search', component: SearchView },
    /* route:latency */ { path: '/latency', name: 'latency', component: LatencyView },
    /* route:credentials */
    {
        // Vault listing: any signed-in user may read it — the api keeps
        // passwords out of list responses and gates the writes itself.
        name: 'credentials',
        path: '/credentials',
        component: CredentialsView,
        meta: { auth: true }
    },
    {
        // Create/edit lives in the app, one component for both doors:
        // /credentials/new carries no id (create, POST) and
        // /credentials/:id/edit passes the record id as a prop (load,
        // then PUT). Same split as the user editor above.
        name: 'credential-new',
        path: '/credentials/new',
        component: CredentialEditView,
        meta: { auth: true }
    },
    // The vault record itself needs a signed-in tab, same as the
    // classic credential_view.php behind check_session.php.
    detail('/credentials/:id', 'credential', CredentialDetailView, '/credential_view.php', 'credentials', { auth: true }),
    {
        name: 'credential-edit',
        path: '/credentials/:id/edit',
        component: CredentialEditView,
        props: true,
        meta: { auth: true }
    },
    /* route:server */
    {
        // Runtime page, in the app now. Public like the classic
        // /classic/server.php it replaces — no meta.auth.
        name: 'runtime',
        path: '/runtime',
        component: ServerView
    },
    /* route:api-docs */
    {
        // Swagger UI lives in the app now, replacing the plain /api-docs
        // doc root. Public — the API reference is readable signed out.
        // The hash path /api (#/api) is scheme-distinct from the
        // /cgi-bin/api data endpoints, so the short path is safe.
        name: 'api',
        path: '/api',
        component: ApiDocsView
    },
    /* route:login */
    {
        // Sign-in lives in the app now: the form posts JSON to the
        // login API and the JWT rides sessionStorage for this tab
        // only. The classic /login.php stays up alongside.
        name: 'login',
        path: '/login',
        component: LoginView
    }
]

export const router = createRouter({
    history: createWebHashHistory(),
    routes
})

/*
 * Sign-in gate. Routes flagged meta.auth resolve the session before
 * they hand over: a signed-out (or expired) tab is bounced to /login
 * with the intended path parked in ?redirect= for the sign-in page to
 * pick up. Public routes never wait on the session API — the
 * dashboard and its monitor/agent/target drill-down stay reachable
 * even when the API is down, matching the classic console where
 * agent.php/monitor.php/target.php sit outside check_session.php.
 */
router.beforeEach(async (to, from, next) => {
    if (!to.meta.auth) {
        next()
        return
    }
    const session = await getSession()
    if (session.authenticated) {
        next()
        return
    }
    next({ name: 'login', query: { redirect: to.fullPath } })
})

export default router