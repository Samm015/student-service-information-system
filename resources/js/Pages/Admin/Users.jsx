import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import {
  Button, Card, Empty, Field, FilterBar, Input, Modal, PageHeader, Pager, SearchInput, Select, StatusBadge, Table, useDialogs,
} from '../../Components/ui';
import { useListFilters } from '../../Components/useListFilters';

const STAFF = ['registration', 'cashier', 'department'];
const YEARS = [1, 2, 3, 4].map((n) => ({ value: n, label: `Year ${n}` }));

function UserForm({ mode, user, options, onClose }) {
  const editing = mode === 'edit';
  const isStudent = (editing ? user.role : null) === 'student';
  const { data, setData, post, patch, processing, errors, transform } = useForm({
    name: user?.name ?? '',
    email: user?.email ?? '',
    role: user?.role ?? 'student',
    password: '',
    department_id: user?.department_id ?? '',
    student_no: user?.student_no ?? '',
    program: user?.program ?? '',
    year_level: user?.year_level ?? 1,
  });

  const needsDept = STAFF.includes(data.role) || data.role === 'student';
  // Students belong to an academic department; staff may belong to an academic department or an office.
  const departments = options.departments.filter((d) => data.role !== 'student' || d.type === 'academic');

  // Send only the fields that matter for the chosen role.
  transform((d) => ({
    name: d.name, email: d.email, role: d.role, password: d.password,
    ...(needsDept ? { department_id: d.department_id } : {}),
    ...(d.role === 'student' ? { student_no: d.student_no, program: d.program, year_level: d.year_level } : {}),
  }));

  const submit = (e) => {
    e.preventDefault();
    const opts = { preserveScroll: true, onSuccess: onClose };
    if (editing) patch(user.update_url, opts); else post(options.store_url, opts);
  };

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={editing ? `Edit ${user.name}` : 'Add user'}
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancel</Button>
        <Button form="user-form" type="submit" loading={processing}>{editing ? 'Save changes' : 'Create account'}</Button>
      </>}
    >
      <form id="user-form" onSubmit={submit} className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Full name" required error={errors.name}>
            <Input kind="text" value={data.name} onChange={(e) => setData('name', e.target.value)} />
          </Field>
          <Field label="Email" required error={errors.email}>
            <Input type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
          </Field>
          <Field label="Role" required error={errors.role} hint={isStudent ? 'The role of a student account cannot be changed.' : user?.is_self ? 'You cannot change your own role.' : undefined}>
            <Select
              value={data.role}
              disabled={isStudent || user?.is_self}
              onChange={(e) => setData('role', e.target.value)}
              options={options.roles.filter((r) => editing ? (isStudent ? r.value === 'student' : r.value !== 'student') : true)}
            />
          </Field>
          <Field
            label={editing ? 'New password' : 'Password'}
            required={!editing}
            error={errors.password}
            hint={editing ? 'Leave empty to keep the current password.' : 'At least 8 characters.'}
          >
            <Input type="password" autoComplete="new-password" value={data.password} onChange={(e) => setData('password', e.target.value)} />
          </Field>
        </div>

        {needsDept && (
          <Field label={data.role === 'student' ? 'Department' : 'Department / office'} required error={errors.department_id}
            hint={data.role === 'department' ? 'Clearance and grades are limited to this department.' : undefined}>
            <Select
              value={data.department_id}
              onChange={(e) => setData('department_id', e.target.value === '' ? '' : Number(e.target.value))}
              options={[{ value: '', label: 'Choose…' }, ...departments]}
            />
          </Field>
        )}

        {data.role === 'student' && (
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Student no." required error={errors.student_no} hint="Format 2024-00001">
              <Input kind="id" placeholder="2024-00001" value={data.student_no} onChange={(e) => setData('student_no', e.target.value)} />
            </Field>
            <Field label="Program" required error={errors.program}>
              <Select
                value={data.program}
                onChange={(e) => setData('program', e.target.value)}
                options={[
                  { value: '', label: 'Choose a program…' },
                  ...options.programs,
                ]}
              />
            </Field>
            <Field label="Year level" required error={errors.year_level}>
              <Select value={data.year_level} onChange={(e) => setData('year_level', Number(e.target.value))} options={YEARS} />
            </Field>
          </div>
        )}
      </form>
    </Modal>
  );
}

export default function Users({ filters: initial, rows, pager, options }) {
  const [filters, go, goPage, loading] = useListFilters('/admin/users', initial, {
    only: ['rows', 'pager', 'filters'],
    defaults: { role: 'all', status: 'all' },
  });
  const [form, setForm] = useState(null); // null | { mode, user }
  const { confirm, dialogs } = useDialogs();

  const toggle = async (u) => {
    const turnOff = u.is_active;
    const sure = await confirm({
      title: turnOff ? `Deactivate ${u.name}?` : `Reactivate ${u.name}?`,
      message: turnOff
        ? 'They will be logged out and cannot log in again until you reactivate the account. Their records are kept.'
        : 'They will be able to log in again.',
      confirmLabel: turnOff ? 'Deactivate' : 'Reactivate',
      variant: turnOff ? 'danger' : 'primary',
    });
    if (sure) router.patch(u.status_url, { is_active: !u.is_active }, { preserveScroll: true });
  };

  return (
    <AppLayout title="Users">
      <PageHeader
        back={{ href: '/admin', label: 'Administration' }}
        title="Users"
        description="Create accounts, change roles and switch accounts on or off. Accounts are never deleted so history is kept."
        actions={<Button icon="plus" onClick={() => setForm({ mode: 'create' })}>Add user</Button>}
      />

      <Card>
        <FilterBar>
          <SearchInput
            className="sm:w-72"
            label="Search users"
            placeholder="Search name, email or student no."
            defaultValue={filters.q}
            onChange={(e) => go({ ...filters, q: e.target.value })}
          />
          <div className="sm:w-44">
            <Select
              aria-label="Filter by role"
              value={filters.role}
              onChange={(e) => go({ ...filters, role: e.target.value }, true)}
              options={[{ value: 'all', label: 'All roles' }, ...options.roles]}
            />
          </div>
          <div className="sm:w-40">
            <Select
              aria-label="Filter by status"
              value={filters.status}
              onChange={(e) => go({ ...filters, status: e.target.value }, true)}
              options={[{ value: 'all', label: 'All statuses' }, { value: 'active', label: 'Active' }, { value: 'inactive', label: 'Deactivated' }]}
            />
          </div>
        </FilterBar>

        {rows.length === 0 ? (
          <Empty icon="users" title="No users match">Try a different search or filter.</Empty>
        ) : (
          <Table
            loading={loading}
            cols={['Name', 'Email', 'Role', 'Department', 'Status', '']}
            rows={rows.map((u) => [
              <span key="n"><strong className="text-slate-900">{u.name}</strong>{u.student_no && <span className="block text-xs text-slate-500">{u.student_no}</span>}</span>,
              u.email,
              <span key="r" className="capitalize">{u.role}</span>,
              u.department ?? <span key="d" className="text-slate-400">—</span>,
              <StatusBadge key="s" status={u.is_active ? 'active' : 'inactive'} label={u.is_active ? 'Active' : 'Deactivated'} />,
              <div key="a" className="flex flex-wrap justify-end gap-2">
                <Button size="sm" variant="secondary" icon="edit" onClick={() => setForm({ mode: 'edit', user: u })}>Edit</Button>
                {!u.is_self && (
                  <Button size="sm" variant={u.is_active ? 'ghost' : 'secondary'} onClick={() => toggle(u)}>{u.is_active ? 'Deactivate' : 'Reactivate'}</Button>
                )}
              </div>,
            ])}
          />
        )}
        <Pager pager={pager} onGo={goPage} />
      </Card>

      {form && <UserForm key={form.user?.id ?? 'new'} mode={form.mode} user={form.user} options={options} onClose={() => setForm(null)} />}
      {dialogs}
    </AppLayout>
  );
}
