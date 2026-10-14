import { Link } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import { Card, Grid, Icon, PageHeader, SummaryCard } from '../Components/ui';

const SECTION_ICON = { users: 'users', departments: 'building', subjects: 'book', 'document-types': 'file' };
const ROLE_LABEL = { student: 'Students', registration: 'Registration', cashier: 'Cashier', department: 'Department', admin: 'Admins' };

// Every number on this page is counted from the database when the page opens.
export default function Admin({ stats, roles, sections }) {
  return (
    <AppLayout title="Admin Portal">
      <PageHeader title="Administration" description="Manage accounts and the lists the other portals rely on." />

      <Grid cols="sm:grid-cols-2 xl:grid-cols-4">
        <SummaryCard icon="users" label="Active users" value={stats.users_active} tone="success" hint={`${stats.users_inactive} deactivated`} />
        <SummaryCard icon="academic" label="Pending enrollments" value={stats.pending_enrollments} tone="warning" />
        <SummaryCard icon="file" label="Open document requests" value={stats.open_documents} tone="brand" />
        <SummaryCard icon="ticket" label="Waiting in queue today" value={stats.queue_waiting} tone="brand" hint={`${stats.pending_payments} payments pending`} />
      </Grid>

      <div>
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Manage</h2>
        <Grid cols="sm:grid-cols-2 xl:grid-cols-4">
          {sections.map((s) => (
            <Link
              key={s.key}
              href={s.href}
              className="group flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-colors hover:border-brand-300 hover:bg-brand-50/40"
            >
              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700"><Icon name={SECTION_ICON[s.key]} /></span>
              <span className="min-w-0 flex-1">
                <span className="block font-semibold text-slate-900">{s.title}</span>
                <span className="block text-sm text-slate-500">{s.note}</span>
                <span className="mt-1 block text-xs font-medium text-slate-600">{s.count} total</span>
              </span>
              <Icon name="arrow" className="h-4 w-4 text-slate-300 group-hover:text-brand-600" />
            </Link>
          ))}
        </Grid>
      </div>

      <Card title="Accounts by role">
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          {roles.map((r) => (
            <li key={r.role} className="rounded-lg bg-slate-50 px-4 py-3">
              <p className="text-2xl font-bold text-slate-900">{r.total}</p>
              <p className="text-sm text-slate-600">{ROLE_LABEL[r.role] ?? r.role}</p>
            </li>
          ))}
        </ul>
      </Card>
    </AppLayout>
  );
}
