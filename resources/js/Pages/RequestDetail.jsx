import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import { Button, Card, DetailList, Grid, PageHeader, StatusBadge, StepTracker, Table, Timeline, useDialogs } from '../Components/ui';

// One request (enrollment or document) in detail, read-only for the student. The server decides what is shown.
export default function RequestDetail({ title, back, status, summary, table, timeline, tracker = null, cancel }) {
  const [busy, setBusy] = useState(false);
  const { confirm, dialogs } = useDialogs();

  const doCancel = async () => {
    const sure = await confirm({ title: 'Cancel this request?', message: 'The request will be closed.', confirmLabel: cancel.label, variant: 'danger' });
    if (sure) router.post(cancel.url, {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });
  };

  return (
    <AppLayout title={title}>
      <PageHeader title={title} back={back} actions={<StatusBadge status={status.value} label={status.label} />} />

      {tracker && (
        <Card><StepTracker steps={tracker.steps} current={tracker.current} /></Card>
      )}

      <Grid cols="lg:grid-cols-2">
        <Card title="Details">
          <DetailList items={summary} />
          {cancel && (
            <div className="mt-4"><Button variant="danger" loading={busy} onClick={doCancel}>{cancel.label}</Button></div>
          )}
        </Card>

        <Card title="Status history"><Timeline items={timeline} /></Card>
      </Grid>

      {table && (
        <Card title={table.title}><Table cols={table.cols} rows={table.rows} /></Card>
      )}
      {dialogs}
    </AppLayout>
  );
}
