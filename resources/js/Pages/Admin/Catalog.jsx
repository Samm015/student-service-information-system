import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import {
  Button, Card, Empty, Field, FilterBar, Input, Modal, PageHeader, Pager, SearchInput, Select, StatusBadge, Table, useDialogs,
} from '../../Components/ui';
import { useListFilters } from '../../Components/useListFilters';

// One page for Departments, Subjects and Document Types. The server describes the columns and the form fields.
function FieldInput({ field, value, onChange }) {
  if (field.input === 'select') {
    return <Select value={value} onChange={(e) => onChange(e.target.value === '' ? '' : (Number.isNaN(Number(e.target.value)) ? e.target.value : Number(e.target.value)))} options={[{ value: '', label: 'Choose…' }, ...field.options]} />;
  }
  const kind = field.input === 'number' ? 'number' : 'any';
  return <Input kind={kind} value={value} onChange={(e) => onChange(e.target.value)} />;
}

function RowForm({ config, row, onClose }) {
  const editing = !!row;
  const initial = Object.fromEntries(config.fields.map((f) => [f.name, row?.[f.name] ?? (f.input === 'select' ? '' : '')]));
  const { data, setData, post, patch, processing, errors } = useForm({ ...initial, is_active: row?.is_active ?? true });

  const submit = (e) => {
    e.preventDefault();
    const opts = { preserveScroll: true, onSuccess: onClose };
    if (editing) patch(row.update_url, opts); else post(config.store_url, opts);
  };

  return (
    <Modal
      open
      onClose={onClose}
      title={editing ? `Edit ${config.singular}` : `Add ${config.singular}`}
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancel</Button>
        <Button form="catalog-form" type="submit" loading={processing}>{editing ? 'Save changes' : `Add ${config.singular}`}</Button>
      </>}
    >
      <form id="catalog-form" onSubmit={submit} className="space-y-4">
        {config.fields.map((f) => (
          <Field key={f.name} label={f.label} required={f.required} error={errors[f.name]} hint={f.hint}>
            <FieldInput field={f} value={data[f.name]} onChange={(v) => setData(f.name, v)} />
          </Field>
        ))}
        {editing && (
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
            Active (available for use)
          </label>
        )}
      </form>
    </Modal>
  );
}

export default function Catalog({ resource, config, filters: initial, rows, pager }) {
  const path = `/admin/${resource}`;
  const [filters, go, goPage, loading] = useListFilters(path, initial, {
    only: ['rows', 'pager', 'filters'],
    defaults: { status: 'all' },
  });
  const [form, setForm] = useState(null); // null | { row }
  const { confirm, dialogs } = useDialogs();

  const extraKey = config.filter?.name;

  const toggle = async (row) => {
    const turnOff = row.is_active;
    const sure = await confirm({
      title: `${turnOff ? 'Deactivate' : 'Reactivate'} ${row.code ?? row.name}?`,
      message: turnOff
        ? `It will no longer be offered for new use. Existing records that already use it are kept.`
        : 'It will be available for use again.',
      confirmLabel: turnOff ? 'Deactivate' : 'Reactivate',
      variant: turnOff ? 'danger' : 'primary',
    });
    if (!sure) return;
    const payload = Object.fromEntries(config.fields.map((f) => [f.name, row[f.name]]));
    router.patch(row.update_url, { ...payload, is_active: !row.is_active }, { preserveScroll: true });
  };

  return (
    <AppLayout title={config.title}>
      <PageHeader
        back={{ href: '/admin', label: 'Administration' }}
        title={config.title}
        description={config.description}
        actions={<Button icon="plus" onClick={() => setForm({})}>Add {config.singular}</Button>}
      />

      <Card>
        <FilterBar>
          <SearchInput
            className="sm:w-72"
            label={`Search ${config.title.toLowerCase()}`}
            placeholder="Search"
            defaultValue={filters.q}
            onChange={(e) => go({ ...filters, q: e.target.value })}
          />
          {config.filter && (
            <div className="sm:w-48">
              <Select
                aria-label={config.filter.label}
                value={filters[extraKey] ?? ''}
                onChange={(e) => go({ ...filters, [extraKey]: e.target.value }, true)}
                options={[{ value: '', label: config.filter.label }, ...config.filter.options]}
              />
            </div>
          )}
          <div className="sm:w-40">
            <Select
              aria-label="Filter by status"
              value={filters.status}
              onChange={(e) => go({ ...filters, status: e.target.value }, true)}
              options={[{ value: 'all', label: 'All statuses' }, { value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]}
            />
          </div>
        </FilterBar>

        {rows.length === 0 ? (
          <Empty icon="info" title={`No ${config.title.toLowerCase()} found`}>Try a different search, or add one.</Empty>
        ) : (
          <Table
            loading={loading}
            cols={[...config.columns.map((c) => c.label), 'Status', '']}
            rows={rows.map((r) => [
              ...config.columns.map((c, i) => (i === 0 ? <strong key={c.key} className="text-slate-900">{r[c.key]}</strong> : r[c.key])),
              <StatusBadge key="s" status={r.is_active ? 'active' : 'inactive'} label={r.is_active ? 'Active' : 'Inactive'} />,
              <div key="a" className="flex flex-wrap justify-end gap-2">
                <Button size="sm" variant="secondary" icon="edit" onClick={() => setForm({ row: r })}>Edit</Button>
                <Button size="sm" variant={r.is_active ? 'ghost' : 'secondary'} onClick={() => toggle(r)}>{r.is_active ? 'Deactivate' : 'Reactivate'}</Button>
              </div>,
            ])}
          />
        )}
        <Pager pager={pager} onGo={goPage} />
      </Card>

      {form && <RowForm key={form.row?.id ?? 'new'} config={config} row={form.row} onClose={() => setForm(null)} />}
      {dialogs}
    </AppLayout>
  );
}
