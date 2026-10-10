const drawer = document.querySelector('#main-navigation');
const backdrop = document.querySelector('.bb-backdrop');
const toggles = [...document.querySelectorAll('[data-nav-open]')];
let trigger;
const closeNavigation = () => {
    document.body.classList.remove('bb-nav-open');
    if (backdrop) backdrop.hidden = true;
    toggles.forEach(button => button.setAttribute('aria-expanded', 'false'));
    if (drawer) drawer.inert = drawer.classList.contains('bb-public-drawer') || innerWidth < 1024;
    trigger?.focus();
};
toggles.forEach(button => button.addEventListener('click', () => {
    trigger = button;
    document.body.classList.add('bb-nav-open');
    if (backdrop) backdrop.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    drawer.inert = false;
    drawer.focus();
}));
document.querySelectorAll('[data-nav-close]').forEach(button => button.addEventListener('click', closeNavigation));
document.addEventListener('keydown', event => {
    if (!document.body.classList.contains('bb-nav-open')) return;
    if (event.key === 'Escape') { event.preventDefault(); closeNavigation(); }
    if (event.key === 'Tab' && drawer) {
        const elements = [...drawer.querySelectorAll('a[href],button:not(:disabled)')];
        const first = elements[0], last = elements.at(-1);
        if (event.shiftKey && (document.activeElement === first || document.activeElement === drawer)) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
});
const syncDrawer = () => { if (drawer) drawer.inert = (drawer.classList.contains('bb-public-drawer') || innerWidth < 1024) && !document.body.classList.contains('bb-nav-open'); };
window.addEventListener('resize', syncDrawer);
syncDrawer();
