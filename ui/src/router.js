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
import LoginView from './components/LoginView.vue'

/*
 * Detail pages take the record id as a prop and keep their list route
 * in meta, so the back-to-list links stay one-liners. The canonical
 * path rides the plural listing (/monitors/:id) and the singular form
 * stays as an alias because detailShared.detailLink still builds
 * '#/monitor/<uuid>' hrefs; drop the alias once that builder is
 * updated.
 */
function detail(path, name, component, legacy, list, alias) {
    return {
        path,
        alias,
        name,
        component,
        props: true,
        meta: { legacy, list }
    }
}

const routes = [
    { path: '/', name: 'dashboard', component: DashboardView },
    /* route:monitors */ { path: '/monitors', name: 'monitors', component: MonitorsView },
    /* route:agents */ { path: '/agents', name: 'agents', component: AgentsView },
    /* route:targets */ { path: '/targets', name: 'targets', component: TargetsView },
    /* route:users */
    {
        // Admin-gated listing backed by GET /cgi-bin/api/users; the
        // view itself shows "admin only" to everyone else. Create and
        // edit live in the app on the routes just below.
        name: 'users',
        path: '/users',
        component: UsersView
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
        component: UserEditView
    },
    {
        name: 'user-edit',
        path: '/users/:id/edit',
        component: UserEditView,
        props: true
    },
    /* route:monitor-new */ { path: '/monitors/new', name: 'monitor-new', component: MonitorEditView },
    /* route:monitor-edit */ { path: '/monitors/:id/edit', name: 'monitor-edit', component: MonitorEditView, props: true },
    /* route:monitor */ detail('/monitors/:id', 'monitor', MonitorDetailView, '/monitor.php', 'monitors', '/monitor/:id'),
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
        component: AgentEditView
    },
    {
        name: 'agent-edit',
        path: '/agents/:id/edit',
        component: AgentEditView,
        props: true
    },
    /* route:agent */ detail('/agents/:id', 'agent', AgentDetailView, '/agent.php', 'agents', '/agent/:id'),
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
        component: TargetEditView
    },
    {
        name: 'target-edit',
        path: '/targets/:id/edit',
        component: TargetEditView,
        props: true
    },
    /* route:target */ detail('/targets/:id', 'target', TargetDetailView, '/target.php', 'targets', '/target/:id'),
    /* route:search */ { path: '/search', name: 'search', component: SearchView },
    /* route:latency */ { path: '/latency', name: 'latency', component: LatencyView },
    /* route:credentials */
    {
        // Vault listing: any signed-in user may read it — the api keeps
        // passwords out of list responses and gates the writes itself.
        name: 'credentials',
        path: '/credentials',
        component: CredentialsView
    },
    {
        // Create/edit lives in the app, one component for both doors:
        // /credentials/new carries no id (create, POST) and
        // /credentials/:id/edit passes the record id as a prop (load,
        // then PUT). Same split as the user editor above.
        name: 'credential-new',
        path: '/credentials/new',
        component: CredentialEditView
    },
    detail('/credentials/:id', 'credential', CredentialDetailView, '/credential_view.php', 'credentials'),
    {
        name: 'credential-edit',
        path: '/credentials/:id/edit',
        component: CredentialEditView,
        props: true
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

export default router