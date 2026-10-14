import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import {
  Button, Card, DetailList, Empty, Field, FilterBar, Grid, Input, Modal, PageHeader, Pager, SearchInput, Select,
  StatusBadge, SummaryCard, Table, Tabs, useDialogs,
} from '../Components/ui';
import { QueueManager } from '../Components/QueuePanel';
import { useListFilters } from '../Components/useListFilters';
import { usePolling } from '../Components/usePolling';
import ClearanceSignoff from '../Components/ClearanceSignoff';

function WalkInForm({ feeTypes, today }) {
  const [formKey, setFormKey] = useState(0);
  const { data, setData, post, processing, errors, reset } = useForm({
    student_no: '', fee_type: feeTypes[0]?.value ?? '', amount: '', paid_on: today, remarks: '',
  });

  return (
    <Card title="Walk-in payment" description="For tuition, miscellaneous and clearance fees. Document fees appear under Pending.">
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          post('/cashier/payments', {
            preserveScroll: true,
            onSuccess: () => { reset('student_no', 'amount', 'remarks'); setFormKey((k) => k + 1); },
          });
        }}
      >
        <Field label="Student No." required error={errors.student_no}>
          <Input key={`s${formKey}`} kind="id" placeholder="2024-00001" onChange={(e) => setData('student_no', e.target.value)} />
        </Field>
        <Field label="Fee type" required error={errors.fee_type}>
          <Select value={data.fee_type} onChange={(e) => setData('fee_type', e.target.value)} options={feeTypes} />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Amount (₱)" required error={errors.amount}>
            <Input key={`a${formKey}`} kind="number" placeholder="0.00" onChange={(e) => setData('amount', e.target.value)} />
          </Field>
          <Field label="Payment date" required error={errors.paid_on}>
            <Input type="date" value={data.paid_on} max={today} onChange={(e) => setData('paid_on', e.target.value)} />
          </Field>
        </div>
        <Field label="Remarks" error={errors.remarks}>
          <Input key={`r${formKey}`} kind="any" placeholder="Optional" onChange={(e) => setData('remarks', e.target.value)} />
        </Field>
        <Button type="submit" loading={processing} className="w-full">{processing ? 'Saving…' : 'Issue receipt'}</Button>
      </form>
    </Card>
  );
}

// Opens when the cashier presses "Record" on a pending payment. The server compares the amount again.
function SettleModal({ payment, today, onClose }) {
  const { data, setData, post, processing, errors } = useForm({ amount: payment.amount_raw, paid_on: today, remarks: '' });

  return (
    <Modal
      open
      onClose={onClose}
      title="Record payment"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Close</Button>
        <Button form="settle-form" type="submit" loading={processing}>{processing ? 'Saving…' : 'Confirm payment'}</Button>
      </>}
    >
      <DetailList items={[
        ['Student', `${payment.student} (${payment.student_no})`],
        ['Fee', `${payment.fee_label}${payment.reference !== '—' ? ` · ${payment.reference}` : ''}`],
        ['Amount due', <strong key="a">₱{payment.amount}</strong>],
      ]} />
      <form
        id="settle-form"
        className="mt-4 space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          post(payment.pay_url, { preserveScroll: true, onSuccess: onClose });
        }}
      >
        <div className="grid grid-cols-2 gap-3">
          <Field label="Amount received (₱)" required error={errors.amount} hint="Must equal the amount due.">
            <Input kind="number" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
          </Field>
          <Field label="Payment date" required error={errors.paid_on}>
            <Input type="date" value={data.paid_on} max={today} onChange={(e) => setData('paid_on', e.target.value)} />
          </Field>
        </div>
        <Field label="Remarks" error={errors.remarks}>
          <Input kind="any" placeholder="Optional" onChange={(e) => setData('remarks', e.target.value)} />
        </Field>
        <p className="text-xs text-slate-500">An official receipt (OR) number is generated automatically.</p>
      </form>
    </Modal>
  );
}

export default function Cashier({
  stats,
  payments,
  fee_types,
  today,
  queue,
  clearance,
}) {
  const live = usePolling(['queue', 'stats', 'payments'], { interval: 4000 });
  const [selected, setSelected] = useState(null);
  const { askReason, dialogs } = useDialogs();
  const [filters, go, goPage, loading] = useListFilters('/cashier', payments.filters, {
    only: ['payments'],
    defaults: { tab: 'pending' },
  });

  const voidIt = async (p) => {
    const reason = await askReason({
      title: `Void ${p.or_number !== '—' ? p.or_number : 'this charge'}?`,
      message: 'A voided receipt stays in the history. A reason is required.',
      label: 'Reason for voiding',
      confirmLabel: 'Void',
    });
    if (reason === null) return;
    router.post(p.void_url, { remarks: reason }, { preserveScroll: true, onSuccess: () => setSelected(null) });
  };

  // Keep the open dialog in sync with fresh data (it closes if that payment is no longer pending).
  const open = selected ? payments.rows.find((p) => p.id === selected && p.can_pay) : null;

  return (
    <AppLayout title="Cashier Portal">
      <PageHeader title="Cashier" description="Record payments, issue official receipts and call payment numbers." />

      <Grid cols="sm:grid-cols-3">
        <SummaryCard icon="payment" label="Collections today" value={stats.collections} tone="success" />
        <SummaryCard icon="clock" label="Pending payments" value={stats.pending} tone="warning" />
        <SummaryCard icon="file" label="Receipts issued today" value={stats.receipts} tone="brand" />
      </Grid>

      <Grid cols="lg:grid-cols-3">
        <WalkInForm feeTypes={fee_types} today={today} />

        <Card title="Transactions" className="lg:col-span-2">
          <FilterBar>
            <Tabs
              tabs={[['pending', 'Pending'], ['history', 'History']]}
              value={filters.tab}
              onChange={(tab) => go({ ...filters, tab }, true)}
            />
            <SearchInput
              className="sm:w-72"
              label="Search payments"
              placeholder="Student, DR- reference or OR no."
              defaultValue={filters.q}
              onChange={(e) => go({ ...filters, q: e.target.value })}
            />
          </FilterBar>
          {payments.rows.length === 0 ? (
            <Empty icon="payment" title={filters.tab === 'pending' ? 'No payments are waiting' : 'No payments match'}>
              {filters.tab === 'pending' ? 'Document fees show up here once Registration accepts a request.' : 'Try a different search.'}
            </Empty>
          ) : (
            <Table
              loading={loading}
              cols={['Student', 'Fee', 'Reference', 'Amount', 'Status', 'OR No.', 'Date', '']}
              rows={payments.rows.map((p) => [
                <span key="s">{p.student} <span className="text-slate-400">({p.student_no})</span></span>,
                p.fee_label,
                p.reference,
                <strong key="a" className="text-slate-900">₱{p.amount}</strong>,
                <StatusBadge key="st" status={p.status === 'paid' ? 'paid' : p.status === 'void' ? 'void' : 'pending'} label={p.status_label} />,
                p.or_number,
                p.date,
                <div key="x" className="flex flex-wrap justify-end gap-2">
                  {p.can_pay && <Button size="sm" onClick={() => setSelected(p.id)}>Record</Button>}
                  {p.can_void && <Button size="sm" variant="secondary" onClick={() => voidIt(p)}>Void</Button>}
                </div>,
              ])}
            />
          )}
          <Pager pager={payments.pager} onGo={goPage} />
        </Card>
      </Grid>
      <ClearanceSignoff
        clearance={clearance}
        basePath="/cashier"
        officeLabel="the Cashier's office"
      />
      <QueueManager queue={queue} live={live} title="Payment window" />

      {open && <SettleModal key={open.id} payment={open} today={today} onClose={() => setSelected(null)} />}
      {dialogs}
    </AppLayout>
  );
}
