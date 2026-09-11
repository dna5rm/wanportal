/*
 * Shared form-exit helper for the edit views (AgentEditView,
 * TargetEditView, MonitorEditView, UserEditView, CredentialEditView).
 *
 * A create-save and every cancel return to wherever the form was
 * opened from — the listing, a detail page, wherever the user really
 * was — instead of teleporting to one hard-coded list. vue-router 4
 * writes the previous entry into history.state.back on every in-app
 * navigation that has one, so a truthy `back` means "there is a real
 * previous page" and router.back() honors it; a direct load (fresh
 * tab, pasted URL) carries back: null and falls back to the named
 * list route.
 *
 * window.history.state is the right read for the production hash
 * history (it mirrors the window entry); the specs' memory history
 * never writes it, so their bare mounts exercise the fallback branch
 * and a stubbed state covers the back branch.
 */
export function leaveForm(router, fallbackName) {
    const st = window.history.state
    if (st && st.back) {
        router.back()
        return
    }
    router.push({ name: fallbackName })
}