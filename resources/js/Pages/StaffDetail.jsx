import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import { Button, Card, DetailList, Field, Grid, Input, PageHeader, StatusBadge, StepTracker, Table, Textarea, Timeline, useDialogs } from '../Components/ui';

// Registration sets or changes the expected release date. Students only ever see it.
function ReleaseDate({ config }) {
  const { data, setData, patch, processing, errors } = useForm({ expected_release_date: config.value });

  // Keep the field in sync after the server saves a new value.
  useEffect(() => { setData('expected_release_date', config.value); }, [config.value]);

  return (
    <Card title="Expected release date" description="The student can see this date but cannot change it.">
      <form
        className="space-y-3"
        onSubmit={(e) => { e.preventDefault(); patch(config.url, { preserveScroll: true }); }}
      >
        <Field label="Release date" required error={errors.expected_release_date} hint={config.hint}>
          <Input type="date" min={config.min} value={data.expected_release_date} onChange={(e) => setData('expected_release_date', e.target.value)} />
        </Field>
        <Button type="submit" loading={processing} disabled={!data.expected_release_date || data.expected_release_date === config.value}>
          {config.value ? 'Update release date' : 'Set release date'}
        </Button>
      </form>
    </Card>
  );
}

// One record in detail for staff (enrollment review, document request).
// The server decides which buttons exist (`review.actions`) and enforces every rule again.
export default function StaffDetail({ title, back, status, summary, table, timeline, tracker = null, release_date = null, review }) {
  const form = useForm({ remarks: '' });
  const [remarks, setRemarks] = useState('');
  const { confirm, askReason, dialogs } = useDialogs();

  const submit = async (action) => {
    let note = remarks.trim();

    if (action.value === 'rejected') {
      const reason = await askReason({ title: action.confirm ?? 'Reject this request?', label: 'Reason (the student will see it)', confirmLabel: 'Reject' });
      if (reason === null) return;
      note = reason;
    } else if (action.confirm || action.value === 'released') {
      const sure = await confirm({
        title: action.label,
        message: action.confirm ?? 'This records that the document was handed to the student and cannot be undone.',
        confirmLabel: action.label,
      });
      if (!sure) return;
    }

    form.transform(() => ({ remarks: note, status: action.value }));
    form.patch(review.url, { preserveScroll: true, onSuccess: () => { setRemarks(''); form.reset('remarks'); } });
  };

  return (
    <AppLayout title={title}>
      <PageHeader title={title} back={back} actions={<StatusBadge status={status.value} label={status.label} />} />

      {tracker && <Card><StepTracker steps={tracker.steps} current={tracker.current} /></Card>}

      <Grid cols="lg:grid-cols-2">
        <Card title="Details"><DetailList items={summary} /></Card>

        <div className="space-y-4">
          {review && (
            <Card title={review.title}>
              {review.note && <p className="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{review.note}</p>}
              <div className="space-y-3">
                <Field label={review.remarks.label.replace(' (required when rejecting)', ' (optional)')} error={form.errors.remarks || form.errors.status}>
                  <Textarea rows={2} value={remarks} onChange={(e) => setRemarks(e.target.value)} placeholder="Type a short note" maxLength={500} />
                </Field>
                <div className="flex flex-wrap gap-2">
                  {review.actions.map((a) => (
                    <Button key={a.value} variant={a.value === 'rejected' ? 'danger' : 'primary'} loading={form.processing} onClick={() => submit(a)}>
                      {a.label}
                    </Button>
                  ))}
                </div>
              </div>
            </Card>
          )}

          {release_date && <ReleaseDate config={release_date} />}

          <Card title="Status history"><Timeline items={timeline} /></Card>
        </div>
      </Grid>

      {table && <Card title={table.title}><Table cols={table.cols} rows={table.rows} /></Card>}
      {dialogs}
    </AppLayout>
  );
}
