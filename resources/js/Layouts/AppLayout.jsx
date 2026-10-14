import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Icon, Notice } from '../Components/ui';

/* Sidebar menus. Hiding a link is only cosmetic: every route is protected on the server. */
const PORTALS = {
  student: { label: 'Student Portal', to: '/student', icon: 'academic' },
  registration: { label: 'Registration Portal', to: '/registration', icon: 'file' },
  cashier: { label: 'Cashier Portal', to: '/cashier', icon: 'payment' },
  department: { label: 'Department Portal', to: '/department', icon: 'building' },
  admin: { label: 'Admin Overview', to: '/admin', icon: 'shield' },
};
const DOCUMENTS = { label: 'Document Requests', to: '/documents', icon: 'file' };
const HISTORY = { label: 'Request History', to: '/student/history', icon: 'clock' };
const ADMIN_SECTIONS = [
  { label: 'Users', to: '/admin/users', icon: 'users' },
  { label: 'Departments', to: '/admin/departments', icon: 'building' },
  { label: 'Subjects', to: '/admin/subjects', icon: 'book' },
  { label: 'Document Types', to: '/admin/document-types', icon: 'file' },
];

const ROLE_LABEL = {
  student: 'Student', registration: 'Registration staff', cashier: 'Cashier',
  department: 'Department staff', admin: 'Administrator',
};

function menuFor(role) {
  if (role === 'admin') {
    return [
      { heading: 'Portals', items: [PORTALS.student, PORTALS.registration, PORTALS.cashier, PORTALS.department, DOCUMENTS] },
      { heading: 'Administration', items: [PORTALS.admin, ...ADMIN_SECTIONS] },
    ];
  }
  const main = [PORTALS[role] ?? PORTALS.student];
  if (role === 'student') main.push(HISTORY);
  main.push(DOCUMENTS);
  return [{ heading: null, items: main }];
}

const initials = (name = '') => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

function Flash({ flash }) {
  const [hidden, setHidden] = useState(false);
  const message = flash.error ?? flash.success;

  // A new message shows again; success messages tidy themselves away after a few seconds.
  useEffect(() => {
    setHidden(false);
    if (!flash.success || flash.error) return undefined;
    const t = setTimeout(() => setHidden(true), 7000);
    return () => clearTimeout(t);
  }, [flash.success, flash.error]);

  if (!message || hidden) return null;
  return <Notice kind={flash.error ? 'error' : 'success'} onDismiss={() => setHidden(true)}>{message}</Notice>;
}

export default function AppLayout({ title, children }) {
  const { props, url } = usePage();
  const user = props.auth.user;
  const [open, setOpen] = useState(false);

  // Safety net: when the user comes back to this page with the Back/Forward
  // buttons (or the browser restores it from cache), ask the server whether the
  // session is still valid. If not, the server redirects to the login page.
  useEffect(() => {
    const recheck = () => router.reload({ only: ['auth'] });
    const onPageShow = (e) => { if (e.persisted) recheck(); };
    const nav = performance.getEntriesByType?.('navigation')?.[0];
    if (nav && nav.type === 'back_forward') recheck();
    window.addEventListener('popstate', recheck);
    window.addEventListener('pageshow', onPageShow);
    return () => {
      window.removeEventListener('popstate', recheck);
      window.removeEventListener('pageshow', onPageShow);
    };
  }, []);

  // Close the mobile menu after navigating, and on Escape.
  useEffect(() => { setOpen(false); }, [url]);
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open]);

  const groups = menuFor(user.role);

  // Highlight only the most specific matching link (/student/history, not also /student).
  const path = url.split('?')[0];
  const activePath = groups
    .flatMap((g) => g.items.map((i) => i.to))
    .filter((p) => path === p || path.startsWith(p + '/'))
    .sort((a, b) => b.length - a.length)[0];

  const flash = props.flash ?? {};

  const sidebar = (
    <div className="flex h-full flex-col">
      <div className="flex items-center gap-3 px-5 py-5">
        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-white"><Icon name="academic" /></span>
        <div className="leading-tight">
          <p className="text-sm font-bold text-slate-900">Student Services</p>
          <p className="text-xs text-slate-500">Information System</p>
        </div>
      </div>

      <nav aria-label="Main menu" className="flex-1 space-y-5 overflow-y-auto px-3 pb-4">
        {groups.map((g, gi) => (
          <div key={gi}>
            {g.heading && <p className="mb-1 px-3 text-xs font-semibold uppercase tracking-wide text-slate-400">{g.heading}</p>}
            <ul className="space-y-0.5">
              {g.items.map((item) => {
                const active = item.to === activePath;
                return (
                  <li key={item.to}>
                    <Link
                      href={item.to}
                      aria-current={active ? 'page' : undefined}
                      className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium ${
                        active ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'}`}
                    >
                      <Icon name={item.icon} className="h-[18px] w-[18px]" />
                      {item.label}
                    </Link>
                  </li>
                );
              })}
            </ul>
          </div>
        ))}
      </nav>

      <div className="border-t border-slate-200 p-3">
        <div className="mb-2 flex items-center gap-3 px-2">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">{initials(user.name)}</span>
          <div className="min-w-0 leading-tight">
            <p className="truncate text-sm font-semibold text-slate-900">{user.name}</p>
            <p className="truncate text-xs text-slate-500">{ROLE_LABEL[user.role] ?? user.role}</p>
          </div>
        </div>
        <button
          type="button"
          onClick={() => router.post('/logout')}
          className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
        >
          <Icon name="logout" className="h-[18px] w-[18px]" /> Log out
        </button>
      </div>
    </div>
  );

  return (
    <div className="min-h-screen lg:flex">
      <Head title={title} />

      {/* Desktop sidebar */}
      <aside className="hidden w-64 shrink-0 border-r border-slate-200 bg-white lg:block">
        <div className="sticky top-0 h-screen">{sidebar}</div>
      </aside>

      {/* Mobile drawer */}
      {open && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="ui-fade absolute inset-0 bg-slate-900/50" onClick={() => setOpen(false)} aria-hidden="true" />
          <aside className="ui-pop absolute inset-y-0 left-0 w-72 max-w-[85%] bg-white shadow-xl">{sidebar}</aside>
        </div>
      )}

      <div className="min-w-0 flex-1">
        <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur lg:hidden">
          <button type="button" onClick={() => setOpen(true)} aria-label="Open menu" aria-expanded={open} className="rounded-lg p-1.5 text-slate-600 hover:bg-slate-100">
            <Icon name="menu" />
          </button>
          <p className="truncate text-sm font-semibold text-slate-900">{title}</p>
        </header>

        <main className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
          <Flash flash={flash} />
          {children}
        </main>
      </div>
    </div>
  );
}
