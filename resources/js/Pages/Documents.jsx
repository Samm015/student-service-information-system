import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import {
  Button, Card, Empty, Field, FilterBar, Grid, Input, LinkButton, PageHeader, Pager, SearchInput, Select, StatusBadge,
  Table, Tabs, TextLink, useDialogs,
} from '../Components/ui';
import { useListFilters } from '../Components/useListFilters';

function StaffDocuments({ staff }) {
  const [filters, go, goPage, loading] = useListFilters('/documents', staff.filters, {
    only: ['staff'],
    defaults: { tab: 'active' },
  });

  return (
    <AppLayout title="Document Requests">
      <PageHeader
        title="Document requests"
        description={staff.can_process
          ? 'Accept requests, set the expected release date, and move each one to ready and released.'
          : 'View only. Registration processes document requests.'}
      />
      <Card>
        <FilterBar>
          <Tabs
            tabs={[['active', 'Needs action'], ['closed', 'Closed'], ['all', 'All']]}
            value={filters.tab}
            onChange={(tab) => go({ ...filters, tab }, true)}
          />
          <SearchInput
            className="sm:w-80"
            label="Search document requests"
            placeholder="Search DR- reference, student no. or name"
            defaultValue={filters.q}
            onChange={(e) => go({ ...filters, q: e.target.value })}
          />
        </FilterBar>

        {staff.rows.length === 0 ? (
          <Empty icon="file" title="No document requests match">Try another tab or a different search.</Empty>
        ) : (
          <Table
            loading={loading}
            cols={['Ref No.', 'Student', 'Document', 'Fee', 'Payment', 'Status', 'Expected release', '']}
            rows={staff.rows.map((r) => [
              <TextLink key="r" href={r.url}>{r.reference_no}</TextLink>,
              <span key="s">{r.student} <span className="text-slate-400">({r.student_no})</span></span>,
              <span key="d">{r.document}<span className="text-slate-400"> × {r.copies}</span></span>,
              `₱${r.fee}`,
              r.payment,
              <StatusBadge key="st" status={r.status} label={r.status_label} />,
              r.expected_release ?? <span key="e" className="text-slate-400">Not set</span>,
              <LinkButton key="o" href={r.url} size="sm" variant={staff.can_process ? 'primary' : 'secondary'}>{staff.can_process ? 'Open' : 'View'}</LinkButton>,
            ])}
          />
        )}
        <Pager pager={staff.pager} onGo={goPage} />
      </Card>
    </AppLayout>
  );
}

export default function Documents({ is_student, staff = null, types = [], requests = [], max_copies = 10 }) {
  const [formKey, setFormKey] = useState(0); // remounts the text input after a successful submit
  const [cancelling, setCancelling] = useState(null);
  const { confirm, dialogs } = useDialogs();
  const { data, setData, post, processing, errors, reset } = useForm({
    document_type_id: types[0]?.id ?? '',
    copies: 1,
    purpose: '',
  });

  if (!is_student && staff) {
    return <StaffDocuments staff={staff} />;
  }

  if (!is_student) {
    return (
      <AppLayout title="Document Requests">
        <PageHeader title="Document requests" />
        <Empty icon="file">Document requests are submitted by students and processed by Registration.</Empty>
      </AppLayout>
    );
  }

  const type = types.find((t) => String(t.id) === String(data.document_type_id));
  const copies = Array.from({ length: max_copies }, (_, i) => i + 1);
  const total = type ? (Number(type.fee.replace(/,/g, '')) * Number(data.copies)).toLocaleString('en-PH', { minimumFractionDigits: 2 }) : null;

  const cancel = async (r) => {
    const sure = await confirm({
      title: `Cancel ${r.reference_no}?`,
      message: 'The request will be closed. You can submit a new one later.',
      confirmLabel: 'Cancel request',
      variant: 'danger',
    });
    if (!sure) return;
    router.post(r.cancel_url, {}, { preserveScroll: true, onStart: () => setCancelling(r.id), onFinish: () => setCancelling(null) });
  };

  return (
    <AppLayout title="Document Requests">
      <PageHeader title="Document requests" description="Request a document. Registration will confirm the fee and tell you when it will be released." />

      <Grid cols="lg:grid-cols-3">
        <Card title="New request">
          {types.length === 0 ? (
            <Empty icon="file">No documents can be requested right now.</Empty>
          ) : (
            <form
              className="space-y-4"
              onSubmit={(e) => {
                e.preventDefault();
                post('/documents', {
                  preserveScroll: true,
                  onSuccess: () => { reset('purpose', 'copies'); setFormKey((k) => k + 1); },
                });
              }}
            >
              <Field label="Document" required error={errors.document_type_id}>
                <Select
                  value={data.document_type_id}
                  onChange={(e) => setData('document_type_id', Number(e.target.value))}
                  options={types.map((t) => ({ value: t.id, label: t.name }))}
                />
              </Field>
              {type && <p className="-mt-2 text-xs text-slate-500">₱{type.fee} per copy · usually about {type.processing_days} working day(s)</p>}
              <Field label="Copies" required error={errors.copies}>
                <Select value={data.copies} onChange={(e) => setData('copies', Number(e.target.value))} options={copies} />
              </Field>
              <Field label="Purpose" required error={errors.purpose} hint="What the document is for.">
                <Input key={`p${formKey}`} kind="any" placeholder="e.g. Employment" onChange={(e) => setData('purpose', e.target.value)} />
              </Field>
              <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                You do not choose a release date. Registration sets it and you will see it on your request.
              </p>
              {total && <p className="text-sm text-slate-700">Estimated fee: <strong className="text-slate-900">₱{total}</strong></p>}
              <Button type="submit" loading={processing}>{processing ? 'Submitting…' : 'Submit request'}</Button>
            </form>
          )}
        </Card>

        <Card title="My requests" className="lg:col-span-2">
          {requests.length === 0 ? (
            <Empty icon="file" title="No requests yet">When you request a document it will show up here.</Empty>
          ) : (
            <Table
              cols={['Ref No.', 'Document', 'Copies', 'Status', 'Expected release', '']}
              rows={requests.map((r) => [
                <TextLink key="r" href={r.url}>{r.reference_no}</TextLink>,
                r.document,
                r.copies,
                <StatusBadge key="s" status={r.status} label={r.status_label} />,
                r.expected_release_date ?? <span key="e" className="text-slate-400">Not set yet</span>,
                r.can_cancel ? <Button key="c" variant="secondary" size="sm" loading={cancelling === r.id} onClick={() => cancel(r)}>Cancel</Button> : '',
              ])}
            />
          )}
        </Card>
      </Grid>
      {dialogs}
    </AppLayout>
  );
}
