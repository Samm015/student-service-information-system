import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import {
  Button, Card, CollapsibleCard, Empty, Field, Grid, Icon, Input, LinkButton, LiveIndicator, ProgressBar,
  QueueTicket, Select, StatusBadge, StepTracker, SummaryCard, Table, TextLink, Timeline, useDialogs, PageHeader, SectionHeader,
} from '../Components/ui';
import { usePolling } from '../Components/usePolling';

const YEAR_LEVELS = [1, 2, 3, 4].map((n) => ({ value: n, label: ['1st', '2nd', '3rd', '4th'][n - 1] + ' Year' }));

/* ---------- Enrollment ---------------------------------------------------- */

function EnrollmentBody({ enrollment, term }) {
  const { terms, subjects, default_year_level, history } = enrollment;
  const [search, setSearch] = useState('');
  const { data, setData, post, processing, errors, transform } = useForm({
    term: terms[0]?.value ?? '',
    year_level: default_year_level,
    subject_ids: [],
  });

  const selectedTerm = terms.find((t) => t.value === data.term);
  const existing = selectedTerm?.enrollment;
  const locked = existing && ['pending', 'approved'].includes(existing.status);

  transform((d) => {
    const [school_year, semester] = d.term.split('|');
    return { school_year, semester, year_level: d.year_level, subject_ids: d.subject_ids };
  });

  const toggle = (id) =>
    setData('subject_ids', data.subject_ids.includes(id) ? data.subject_ids.filter((x) => x !== id) : [...data.subject_ids, id]);

  const q = search.trim().toLowerCase();
  const shown = subjects.filter((s) => !q || s.code.toLowerCase().includes(q) || s.title.toLowerCase().includes(q));
  const totalUnits = subjects.filter((s) => data.subject_ids.includes(s.id)).reduce((sum, s) => sum + s.units, 0);
  const subjectError = errors.subject_ids ?? Object.entries(errors).find(([k]) => k.startsWith('subject_ids.'))?.[1];

  return (
    <div className="space-y-5">
      <Field label="Semester / School Year" error={errors.semester || errors.school_year}>
        <Select value={data.term} onChange={(e) => setData('term', e.target.value)} options={terms.map((t) => ({ value: t.value, label: t.label }))} />
      </Field>

      {locked ? (
        <div className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={existing.status} label={existing.status_label} />
            <span className="text-sm text-slate-600">{selectedTerm.label}</span>
          </div>
          <p className="text-sm text-slate-700">
            {existing.status === 'approved'
              ? 'Your enrollment for this term is approved. You cannot submit another request for it.'
              : 'Your request is waiting for the Registrar’s review. You cannot submit another request for this term.'}
          </p>
          {existing.subjects.length > 0 && (
            <Table
              cols={['Code', 'Subject', 'Units']}
              rows={existing.subjects.map((s) => [<strong key="c">{s.code}</strong>, s.title, s.units])}
            />
          )}
          <p className="text-sm text-slate-600">
            {existing.subjects.length} subject(s) · {existing.units} units · <TextLink href={existing.url}>View full details</TextLink>
          </p>
        </div>
      ) : (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            post('/student/enrollments', { preserveScroll: true, onSuccess: () => setData('subject_ids', []) });
          }}
        >
          {existing && (
            <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
              Your previous request for this term was <strong>{existing.status}</strong>
              {existing.remarks ? `: ${existing.remarks}` : '.'} You can submit a new one.
            </p>
          )}
          <Field label="Year Level" required error={errors.year_level}>
            <Select value={data.year_level} onChange={(e) => setData('year_level', Number(e.target.value))} options={YEAR_LEVELS} />
          </Field>

          <div className="text-sm">
            <span className="mb-1 block font-medium text-slate-700">Subjects <span className="text-red-600" aria-hidden="true">*</span></span>
            <Input kind="any" placeholder="Search subject code or title" onChange={(e) => setSearch(e.target.value)} />
            <div className="mt-2 max-h-56 space-y-0.5 overflow-y-auto rounded-lg border border-slate-200 p-1.5">
              {subjects.length === 0 && <p className="p-2 text-slate-500">No subjects are open for enrollment yet.</p>}
              {subjects.length > 0 && shown.length === 0 && <p className="p-2 text-slate-500">No subject matches your search.</p>}
              {shown.map((s) => (
                <label key={s.id} className={`flex items-start gap-2.5 rounded-md px-2 py-1.5 ${s.passed ? 'opacity-60' : 'cursor-pointer hover:bg-slate-50'}`}>
                  <input
                    type="checkbox"
                    className="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    disabled={s.passed}
                    checked={data.subject_ids.includes(s.id)}
                    onChange={() => toggle(s.id)}
                  />
                  <span className="text-slate-700">
                    <strong className="text-slate-900">{s.code}</strong> — {s.title} ({s.units} units){s.passed && ' · already passed'}
                  </span>
                </label>
              ))}
            </div>
            <p className="mt-1.5 text-slate-600">{data.subject_ids.length} selected · <strong>{totalUnits}</strong> units</p>
            {subjectError && <span role="alert" className="mt-1 flex items-start gap-1 text-xs font-medium text-red-700"><Icon name="alert" className="mt-px h-3.5 w-3.5" /> {subjectError}</span>}
          </div>

          <Button type="submit" loading={processing} disabled={data.subject_ids.length === 0}>
            {processing ? 'Submitting…' : existing ? 'Re-submit enrollment' : 'Submit enrollment'}
          </Button>
        </form>
      )}

      <div>
        <SectionHeader title="My enrollment requests" />
        {history.length === 0 ? (
          <Empty icon="academic">You have not submitted any enrollment request yet.</Empty>
        ) : (
          <Table
            cols={['Term', 'Units', 'Status', '']}
            rows={history.map((h) => [
              h.term_label,
              h.units,
              <StatusBadge key="s" status={h.status} label={h.status_label} />,
              <TextLink key="v" href={h.url}>View</TextLink>,
            ])}
          />
        )}
      </div>
    </div>
  );
}

/* ---------- Clearance ----------------------------------------------------- */

function ClearanceBody({ clearance, canStart, term }) {
  const [busy, setBusy] = useState(false);
  const start = () => router.post('/student/clearance', {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

  if (!clearance) {
    return (
      <Empty
        icon="checklist"
        title="No clearance record yet"
        action={
          canStart ? (
            <Button loading={busy} onClick={start}>
              Start clearance for {term.label}
            </Button>
          ) : null
        }
      >
        {canStart
          ? 'Start your clearance so each office can sign off.'
          : 'An approved enrollment for the current term is required before you can start clearance.'}
      </Empty>
    );
  }

  const pending = clearance.items.filter((i) => i.status !== 'signed');
  const done = clearance.items.filter((i) => i.status === 'signed');
  const rejected = clearance.items.filter((i) => i.status === 'rejected' && i.remarks);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-2 text-sm text-slate-600">
        <span>{clearance.term_label}</span>
        <StatusBadge status={clearance.status} label={clearance.status_label} />
        {clearance.cleared_at && <span>Cleared {clearance.cleared_at}</span>}
      </div>
      <ProgressBar value={clearance.signed} max={clearance.total} label="Offices signed" />

      {clearance.items.length === 0 ? (
        <Empty>This clearance has no requirements.</Empty>
      ) : (
        <div className="grid gap-5 lg:grid-cols-2">
          <div>
            <SectionHeader title={`Pending (${pending.length})`} />
            {pending.length === 0 ? (
              <Empty icon="check">Every requirement is signed.</Empty>
            ) : (
              <ul className="space-y-2">
                {pending.map((i) => (
                  <li key={i.id} className="flex items-start justify-between gap-3 rounded-lg border border-slate-200 p-3">
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-slate-900">{i.requirement}</p>
                      <p className="text-xs text-slate-500">{i.office}</p>
                    </div>
                    <StatusBadge status={i.status} label={i.status_label} />
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div>
            <SectionHeader title={`Completed (${done.length})`} />
            {done.length === 0 ? (
              <Empty>Nothing has been signed yet.</Empty>
            ) : (
              <ul className="space-y-2">
                {done.map((i) => (
                  <li key={i.id} className="flex items-start justify-between gap-3 rounded-lg border border-slate-200 p-3">
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-slate-900">{i.requirement}</p>
                      <p className="text-xs text-slate-500">{i.office} · signed {i.signed_at}</p>
                    </div>
                    <StatusBadge status="signed" label="Signed" />
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}

      {rejected.length > 0 && (
        <ul className="space-y-1 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900">
          {rejected.map((i) => <li key={i.id}><strong>{i.requirement}:</strong> {i.remarks}</li>)}
        </ul>
      )}

      {canStart && <Button loading={busy} onClick={start}>Start clearance for {term.label}</Button>}
    </div>
  );
}

/* ---------- Queue --------------------------------------------------------- */

function QueueBody({ queue, live }) {
  const { data, setData, post, processing, errors } = useForm({ service: queue.services[0]?.value ?? '' });
  const [cancelling, setCancelling] = useState(null);
  const { confirm, dialogs } = useDialogs();

  const cancel = async (entry) => {
    const sure = await confirm({
      title: `Cancel ${entry.code}?`,
      message: 'You will lose your place in line and need a new number if you change your mind.',
      confirmLabel: 'Cancel my number',
      variant: 'danger',
    });
    if (!sure) return;
    router.post(entry.cancel_url, {}, { preserveScroll: true, onStart: () => setCancelling(entry.id), onFinish: () => setCancelling(null) });
  };

  return (
    <div className="space-y-5">
      {queue.active.length === 0 ? (
        <Empty icon="ticket" title="You have no active queue number">Pick a service below to get a number.</Empty>
      ) : (
        <div className="space-y-3">
          <div className="flex justify-end"><LiveIndicator failed={live.failed} /></div>
          {queue.active.map((e) => (
            <QueueTicket
              key={e.id}
              entry={e}
              action={e.can_cancel && <Button variant="ghost" size="sm" loading={cancelling === e.id} onClick={() => cancel(e)}>Cancel number</Button>}
            />
          ))}
        </div>
      )}

      <form
        className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end"
        onSubmit={(e) => {
          e.preventDefault();
          post('/student/queue', { preserveScroll: true });
        }}
      >
        <Field label="Get a queue number for" error={errors.service || errors.student_no}>
          <Select value={data.service} onChange={(e) => setData('service', e.target.value)} options={queue.services} />
        </Field>
        <Button type="submit" icon="ticket" loading={processing}>{processing ? 'Getting number…' : 'Get queue number'}</Button>
      </form>
      {dialogs}
    </div>
  );
}

/* ---------- Documents ----------------------------------------------------- */

const PAYMENT_TONE = { paid: 'success', pending: 'warning' };

function DocumentsBody({ documents }) {
  return (
    <div className="space-y-4">
      {documents.length === 0 ? (
        <Empty icon="file" title="No pending documents">Request a document and you can follow every step here.</Empty>
      ) : (
        <ul className="space-y-4">
          {documents.map((d) => (
            <li key={d.id} className="rounded-lg border border-slate-200 p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="text-sm font-semibold text-slate-900">{d.document} <span className="font-normal text-slate-500">· {d.copies} {d.copies === 1 ? 'copy' : 'copies'}</span></p>
                  <p className="text-xs text-slate-500">{d.reference_no} · requested {d.requested_at}</p>
                </div>
                <StatusBadge status={d.status} label={d.status_label} />
              </div>

              {d.tracker && <div className="mt-4 px-1"><StepTracker steps={d.tracker.steps} current={d.tracker.current} /></div>}

              <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                <div>
                  <dt className="text-xs text-slate-500">Expected release</dt>
                  <dd className="font-semibold text-slate-900">{d.expected_release_date ?? 'To be set by Registration'}</dd>
                </div>
                <div>
                  <dt className="text-xs text-slate-500">Payment</dt>
                  <dd className="flex flex-wrap items-center gap-1.5 font-semibold text-slate-900">
                    {d.payment.status === 'paid' || d.payment.status === 'pending'
                      ? <StatusBadge status={d.payment.status} tone={PAYMENT_TONE[d.payment.status]} label={d.payment.label} />
                      : d.payment.label}
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-slate-500">Fee</dt>
                  <dd className="font-semibold text-slate-900">{d.requires_payment ? `₱${d.fee_total}` : 'Free'}</dd>
                </div>
              </dl>
              <p className="mt-3 text-sm"><TextLink href={d.url}>View request history</TextLink></p>
            </li>
          ))}
        </ul>
      )}
      <div className="flex flex-wrap gap-2">
        <LinkButton href="/documents" variant="primary" icon="plus">Request a document</LinkButton>
        <LinkButton href="/student/history">All requests</LinkButton>
      </div>
    </div>
  );
}

/* ---------- Grades -------------------------------------------------------- */

function GradesBody({ grades, gwa }) {
  if (grades.length === 0) return <Empty icon="chart" title="No grades yet">Grades appear here once your department encodes them.</Empty>;

  const groups = [];
  grades.forEach((g) => {
    const key = `${g.school_year} · ${g.semester}`;
    let group = groups.find((x) => x.key === key);
    if (!group) { group = { key, rows: [] }; groups.push(group); }
    group.rows.push(g);
  });

  return (
    <div className="space-y-5">
      {groups.map((grp) => (
        <div key={grp.key}>
          <SectionHeader title={grp.key} />
          <Table
            cols={['Code', 'Subject', 'Units', 'Grade', 'Remarks']}
            rows={grp.rows.map((g) => [
              <strong key="c" className="text-slate-900">{g.code}</strong>,
              g.title,
              g.units,
              g.grade ?? <span className="text-slate-400">Not yet graded</span>,
              g.remarks ? <StatusBadge key="r" status={g.remarks.toLowerCase() === 'passed' ? 'approved' : g.remarks.toLowerCase() === 'failed' ? 'rejected' : 'pending'} label={g.remarks} /> : '—',
            ])}
          />
        </div>
      ))}
      <p className="text-sm text-slate-600">General weighted average (all graded subjects): <strong className="text-slate-900">{gwa}</strong></p>
    </div>
  );
}

/* ---------- Page ---------------------------------------------------------- */

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

export default function Student({ student, stats, term, enrollment, clearance, can_start_clearance, queue, documents, grades, activity = [] }) {
  // Poll only what staff can change. The enrollment form keeps whatever the student is typing.
  const live = usePolling(['queue', 'stats', 'documents', 'activity', 'clearance'], { interval: 4000, enabled: !!student });

  if (!student) {
    return (
      <AppLayout title="Student Portal">
        <PageHeader title="Student Portal" />
        <Empty icon="user" title="No student profile">This account has no student profile, so there is nothing to show here.</Empty>
      </AppLayout>
    );
  }

  const current = enrollment.history.find((h) => h.school_year === term.school_year && h.semester === term.semester);
  const first = queue.active[0];
  const called = queue.active.find((e) => e.status === 'serving');
  const pendingDocs = documents.filter((d) => ['pending', 'awaiting_payment', 'processing'].includes(d.status)).length;
  const readyDocs = documents.filter((d) => d.status === 'ready').length;
  const awaitingPay = documents.filter((d) => d.status === 'awaiting_payment').length;

  const queueSummary = !first
    ? 'No active number'
    : called ? `${called.code} · Proceed to ${called.counter ?? 'the counter'}`
    : `${first.code} · ${plural(first.ahead, 'person', 'people')} ahead`;
  const docSummary = documents.length === 0 ? 'No active requests'
    : readyDocs ? `${plural(readyDocs, 'document', 'documents')} ready for release`
    : `${plural(documents.length, 'active request', 'active requests')}`;

  // "What needs me?": derived only from real records.
  const todo = [];
  if (called) todo.push(`Your number ${called.code} was called. Please go to ${called.counter ?? 'the counter'}.`);
  if (!current) todo.push(`You have not submitted your enrollment for ${term.label}.`);
  if (awaitingPay) todo.push(`${plural(awaitingPay, 'document request is', 'document requests are')} waiting for payment at the Cashier.`);
  if (readyDocs) todo.push(`${plural(readyDocs, 'document is', 'documents are')} ready for release.`);
  if (!clearance) todo.push('You have not started your clearance yet.');

  return (
    <AppLayout title="Student Portal">
      <PageHeader
        title={`Hello, ${student.name.split(' ')[0]}`}
        description={`${student.student_no} · ${student.program} · ${['1st', '2nd', '3rd', '4th'][student.year_level - 1] ?? student.year_level} year · ${student.department}`}
        actions={<span className="text-sm text-slate-500">{term.label}</span>}
      />

      {todo.length > 0 && (
        <section aria-label="Needs your attention" className="rounded-xl border border-amber-200 bg-amber-50 p-4">
          <p className="mb-2 flex items-center gap-2 text-sm font-semibold text-amber-900"><Icon name="alert" className="h-4 w-4" /> Needs your attention</p>
          <ul className="list-inside list-disc space-y-0.5 text-sm text-amber-900">
            {todo.map((t) => <li key={t}>{t}</li>)}
          </ul>
        </section>
      )}

      <Grid cols="grid-cols-2 xl:grid-cols-4">
        <SummaryCard icon="academic" label="Enrollment" value={stats.enrollment} tone={current?.status === 'approved' ? 'success' : current ? 'warning' : 'neutral'} />
        <SummaryCard icon="ticket" label="My queue number" value={stats.queue} tone={called ? 'brand' : 'neutral'} hint={called ? 'You were called' : undefined} />
        <SummaryCard icon="file" label="Active documents" value={stats.documents} tone={readyDocs ? 'success' : 'brand'} />
        <SummaryCard icon="checklist" label="Clearance" value={stats.clearance} tone="neutral" />
      </Grid>

      <div className="space-y-4">
        <CollapsibleCard
          icon="academic"
          title="Enrollment"
          summary={stats.enrollment}
          badge={current && <StatusBadge status={current.status} label={current.status_label} />}
          attention={!current}
          defaultOpen={!current}
        >
          <EnrollmentBody enrollment={enrollment} term={term} />
        </CollapsibleCard>

        <CollapsibleCard
          icon="ticket"
          title="Queue"
          summary={queueSummary}
          badge={first && <StatusBadge status={first.status} label={first.status_label} />}
          attention={!!called}
          defaultOpen={queue.active.length > 0}
        >
          <QueueBody queue={queue} live={live} />
        </CollapsibleCard>

        <CollapsibleCard
          icon="file"
          title="Documents"
          summary={docSummary}
          badge={documents.length > 0 && pendingDocs > 0 && <StatusBadge status="pending" label={`${pendingDocs} in progress`} />}
          attention={readyDocs > 0 || awaitingPay > 0}
          defaultOpen={documents.length > 0}
        >
          <DocumentsBody documents={documents} />
        </CollapsibleCard>

        <CollapsibleCard
          icon="checklist"
          title="Clearance"
          summary={clearance ? `${clearance.signed}/${clearance.total} completed` : 'Not started'}
          badge={clearance && <StatusBadge status={clearance.status} label={clearance.status_label} />}
          attention={!clearance}
        >
          <ClearanceBody clearance={clearance} canStart={can_start_clearance} term={term} />
        </CollapsibleCard>

        <CollapsibleCard icon="chart" title="Grades" summary={`GWA ${stats.gwa}`}>
          <GradesBody grades={grades} gwa={stats.gwa} />
        </CollapsibleCard>
      </div>

      <Card title="Recent activity" description="The latest changes to your requests.">
        {activity.length === 0 ? (
          <Empty icon="clock">Nothing has happened yet. Submit an enrollment or a document request to get started.</Empty>
        ) : (
          <Timeline items={activity.map((a) => ({
            status: a.status,
            status_label: `${a.what} · ${a.status_label}`,
            at: a.at,
            remarks: a.detail,
          }))} />
        )}
      </Card>
    </AppLayout>
  );
}
