import { router, useForm } from '@inertiajs/react';
import { Button, Card, Empty, Field, LiveIndicator, Select, StatusBadge, Table, useDialogs } from './ui';

const LABELS = { start: 'Start', complete: 'Complete', skip: 'Skip', cancel: 'Cancel' };
const VARIANT = { start: 'primary', complete: 'primary', skip: 'secondary', cancel: 'ghost' };

// Buttons for one queue entry. The server decides which actions exist (entry.actions)
// and checks every rule again when the button is pressed.
function EntryButtons({ entry, ask }) {
  const act = async (action) => {
    if (action === 'skip' || action === 'cancel') {
      const sure = await ask({
        title: `${LABELS[action]} ${entry.code}?`,
        message: action === 'skip'
          ? `${entry.student} will be marked as a no-show and removed from the line.`
          : `${entry.student}’s number will be cancelled.`,
        confirmLabel: `${LABELS[action]} ${entry.code}`,
        variant: 'danger',
      });
      if (!sure) return;
    }
    router.patch(entry.update_url, { action }, { preserveScroll: true });
  };

  return (
    <div className="flex flex-wrap justify-end gap-2">
      {entry.actions.map((a) => (
        <Button key={a} size="sm" variant={VARIANT[a]} onClick={() => act(a)}>{LABELS[a]}</Button>
      ))}
    </div>
  );
}

// Call the next number to a window, and manage the people currently at a window.
export function QueueControl({ queue, ask, title = 'Call next' }) {
  const { data, setData, post, processing, errors } = useForm({
    counter: queue.counters[0] ?? '',
    service: queue.services.length === 1 ? queue.services[0].value : '',
  });

  return (
    <div className="space-y-5">
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault();
          post(queue.call_url, { preserveScroll: true });
        }}
      >
        <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
        <div className={`grid gap-3 ${queue.services.length > 1 ? 'grid-cols-2' : 'grid-cols-1'}`}>
          <Field label="Window" error={errors.counter}>
            <Select
              value={data.counter}
              onChange={(e) => setData('counter', e.target.value)}
              options={queue.counters}
            />
          </Field>

          {queue.services.length > 1 && (
            <Field label="Service" error={errors.service}>
              <Select
                value={data.service}
                onChange={(e) => setData('service', e.target.value)}
                options={queue.services}
              />
            </Field>
          )}
        </div>
        <Button type="submit" icon="arrow" loading={processing} disabled={queue.waiting.length === 0} className="w-full">
          {processing ? 'Calling…' : 'Call next'}
        </Button>
        {queue.waiting.length === 0 && <p className="text-xs text-slate-500">No one is waiting right now.</p>}
      </form>

      <div>
        <h3 className="mb-2 text-sm font-semibold text-slate-900">At a window now</h3>
        {queue.now_serving.length === 0 ? (
          <Empty>Nobody has been called yet.</Empty>
        ) : (
          <ul className="space-y-2">
            {queue.now_serving.map((e) => (
              <li key={e.id} className="space-y-2 rounded-lg border border-brand-200 bg-brand-50/50 p-3">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="text-lg font-bold leading-tight text-slate-900">{e.code}</p>
                    <p className="text-sm text-slate-600">{e.student} · {e.service_label}</p>
                    <p className="text-xs text-slate-500">{e.counter}</p>
                  </div>
                  <StatusBadge status={e.status} label={e.status_label} />
                </div>
                <EntryButtons entry={e} ask={ask} />
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

// People waiting today, and what just finished.
export function QueueLists({ queue, ask }) {
  return (
    <div className="space-y-5">
      <div>
        <h3 className="mb-2 text-sm font-semibold text-slate-900">Waiting today ({queue.waiting.length})</h3>
        {queue.waiting.length === 0 ? (
          <Empty icon="ticket">No students are currently waiting.</Empty>
        ) : (
          <Table
            cols={['No.', 'Student', 'Service', 'Joined', '']}
            rows={queue.waiting.map((e) => [
              <strong key="c" className="text-slate-900">{e.code}</strong>,
              <span key="s">{e.student} <span className="text-slate-400">({e.student_no})</span></span>,
              e.service_label,
              e.time,
              <EntryButtons key="b" entry={e} ask={ask} />,
            ])}
          />
        )}
      </div>

      {queue.recent.length > 0 && (
        <div>
          <h3 className="mb-2 text-sm font-semibold text-slate-900">Recently finished</h3>
          <Table
            cols={['No.', 'Student', 'Service', 'Window', 'Result']}
            rows={queue.recent.map((e) => [
              e.code, e.student, e.service_label, e.counter ?? '—',
              <StatusBadge key="s" status={e.status} label={e.status_label} />,
            ])}
          />
        </div>
      )}
    </div>
  );
}

// The whole queue work area for a window: controls on the left, the line on the right.
export function QueueManager({ queue, live, title = 'Queue management' }) {
  const { confirm, dialogs } = useDialogs();

  return (
    <Card title={title} description="You control the line. Students see your changes within a few seconds." action={<LiveIndicator failed={live?.failed} />}>
      <div className="grid gap-6 lg:grid-cols-3">
        <QueueControl queue={queue} ask={confirm} />
        <div className="lg:col-span-2"><QueueLists queue={queue} ask={confirm} /></div>
      </div>
      {dialogs}
    </Card>
  );
}
