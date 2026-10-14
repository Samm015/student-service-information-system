import { useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import {
  Button, Card, Empty, Field, FilterBar, Grid, Input, PageHeader, Pager, SearchInput, Select, StatusBadge, SummaryCard,
  Table, Tabs, useDialogs,
} from '../Components/ui';
import { useListFilters } from '../Components/useListFilters';

function ClearanceSection({ clearance }) {
  const [filters, go, goPage, loading] = useListFilters('/department', clearance.filters, {
    only: ['clearance'],
    defaults: { tab: 'open' },
  });
  const { confirm, askReason, dialogs } = useDialogs();

  const act = async (row, status) => {
    if (status === 'signed') {
      const sure = await confirm({
        title: `Sign “${row.requirement}”?`,
        message: `${row.student} (${row.student_no}) will be marked as cleared by your office. A signed item is final.`,
        confirmLabel: 'Sign',
      });
      if (sure) router.patch(row.update_url, { status }, { preserveScroll: true });
      return;
    }
    const reason = await askReason({
      title: `Reject “${row.requirement}”?`,
      message: `${row.student} will see your reason.`,
      label: 'Reason',
      confirmLabel: 'Reject',
    });
    if (reason !== null) router.patch(row.update_url, { status, remarks: reason }, { preserveScroll: true });
  };

  return (
    <Card title="Clearance sign-off" description="Only requirements owned by your department appear here.">
      <FilterBar>
        <Tabs
          tabs={[['open', 'Needs action'], ['signed', 'Signed'], ['all', 'All']]}
          value={filters.tab}
          onChange={(tab) => go({ ...filters, tab }, true)}
        />
        <SearchInput
          className="sm:w-72"
          label="Search clearance requirements"
          placeholder="Search student no. or name"
          defaultValue={filters.q}
          onChange={(e) => go({ ...filters, q: e.target.value })}
        />
      </FilterBar>
      {clearance.rows.length === 0 ? (
        <Empty icon="checklist" title="No clearance requirements match">{filters.tab === 'open' ? 'Nothing is waiting for your signature.' : 'Try another tab or search.'}</Empty>
      ) : (
        <Table
          loading={loading}
          cols={['Student', 'Requirement', 'Term', 'Status', 'Signed / remarks', '']}
          rows={clearance.rows.map((r) => [
            <span key="s">{r.student} <span className="text-slate-400">({r.student_no})</span></span>,
            r.requirement,
            r.term_label,
            <StatusBadge key="st" status={r.status} label={r.status_label} />,
            r.status === 'signed' ? r.signed_at : r.remarks ?? '—',
            <div key="a" className="flex flex-wrap justify-end gap-2">
              {r.can_sign && <Button size="sm" onClick={() => act(r, 'signed')}>Sign</Button>}
              {r.can_reject && <Button size="sm" variant="secondary" onClick={() => act(r, 'rejected')}>Reject</Button>}
            </div>,
          ])}
        />
      )}
      <Pager pager={clearance.pager} onGo={goPage} />
      {dialogs}
    </Card>
  );
}

function GradeForm({ grading, prefill }) {
  const [formKey, setFormKey] = useState(0);
  const { data, setData, post, processing, errors, transform } = useForm({
    subject_id: grading.subjects[0]?.value ?? '',
    term: grading.term,
    student_no: '',
    grade: '',
  });

  transform((d) => {
    const [school_year, semester] = d.term.split('|');
    return { subject_id: d.subject_id, school_year, semester, student_no: d.student_no, grade: d.grade };
  });

  // The "Grade" button in the list below fills in the form.
  useEffect(() => {
    if (!prefill) return;
    setData((d) => ({ ...d, subject_id: prefill.subject_id, term: grading.term, student_no: prefill.student_no, grade: '' }));
    setFormKey((k) => k + 1);
  }, [prefill]);

  const termError = errors.school_year || errors.semester;

  return (
    <Card title="Grade encoding" description="1.00 (highest) to 5.00 in steps of 0.25. 3.00 or better is a pass.">
      {grading.subjects.length === 0 ? (
        <Empty icon="book">Your department has no active subjects.</Empty>
      ) : (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            post('/department/grades', {
              preserveScroll: true,
              onSuccess: () => { setData((d) => ({ ...d, student_no: '', grade: '' })); setFormKey((k) => k + 1); },
            });
          }}
        >
          <Field label="Subject" required error={errors.subject_id}>
            <Select value={data.subject_id} onChange={(e) => setData('subject_id', Number(e.target.value))} options={grading.subjects} />
          </Field>
          <Field label="Semester / school year" required error={termError}>
            <Select value={data.term} onChange={(e) => setData('term', e.target.value)} options={grading.terms.map((t) => ({ value: t.value, label: t.label }))} />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Student No." required error={errors.student_no}>
              <Input key={`s${formKey}`} kind="id" placeholder="2024-00001" value={data.student_no} onChange={(e) => setData('student_no', e.target.value)} />
            </Field>
            <Field label="Grade" required error={errors.grade}>
              <Input key={`g${formKey}`} kind="number" placeholder="1.00 – 5.00" value={data.grade} onChange={(e) => setData('grade', e.target.value)} />
            </Field>
          </div>
          <Button type="submit" loading={processing}>{processing ? 'Saving…' : 'Save grade'}</Button>
        </form>
      )}
    </Card>
  );
}

function GradesSection({ grading }) {
  const [prefill, setPrefill] = useState(null);

  return (
    <div className="space-y-4">
      <Grid cols="lg:grid-cols-2">
        <GradeForm grading={grading} prefill={prefill} />

        <Card title={`Waiting for a grade (${grading.to_grade_total})`} description="Students with an approved enrollment who have no grade yet.">
          {grading.to_grade.length === 0 ? (
            <Empty icon="check">Every enlisted student of this term has a grade.</Empty>
          ) : (
            <Table
              cols={['Student No.', 'Student', 'Subject', '']}
              rows={grading.to_grade.map((r) => [
                r.student_no, r.student, r.code,
                <Button key="g" size="sm" onClick={() => setPrefill({ n: Date.now(), subject_id: r.subject_id, student_no: r.student_no })}>Grade</Button>,
              ])}
            />
          )}
          {grading.to_grade_total > grading.to_grade.length && (
            <p className="mt-2 text-xs text-slate-500">Showing the first {grading.to_grade.length}.</p>
          )}
        </Card>
      </Grid>

      <Card title="Recently encoded">
        {grading.recent.length === 0 ? (
          <Empty icon="book">No grades encoded by your department yet.</Empty>
        ) : (
          <Table
            cols={['Student', 'Subject', 'Term', 'Grade', 'Encoded']}
            rows={grading.recent.map((g) => [`${g.student} (${g.student_no})`, g.code, g.term_label, <strong key="g" className="text-slate-900">{g.grade ?? '—'}</strong>, g.encoded_at ?? '—'])}
          />
        )}
      </Card>
    </div>
  );
}

export default function Department({ department, stats, clearance, grading }) {
  const [section, setSection] = useState('clearance');

  if (!department) {
    return (
      <AppLayout title="Department Portal">
        <PageHeader title="Department" />
        <Empty icon="building" title="No department assigned">Your account is not attached to a department, so there is nothing to sign or grade here. Ask the administrator.</Empty>
      </AppLayout>
    );
  }

  return (
    <AppLayout title="Department Portal">
      <PageHeader title="Department" description={`Signed in for ${department.name}`} />

      <Grid cols="sm:grid-cols-3">
        <SummaryCard icon="checklist" label="Clearance requests" value={stats.clearance} tone="warning" hint="Waiting for your signature" />
        <SummaryCard icon="users" label="Enlisted students" value={stats.enlisted} tone="brand" />
        <SummaryCard icon="chart" label="Grades to encode" value={stats.to_grade} tone="warning" />
      </Grid>

      <Tabs
        tabs={[['clearance', 'Clearance'], ['grades', 'Grades']]}
        value={section}
        onChange={setSection}
      />

      {section === 'clearance' ? <ClearanceSection clearance={clearance} /> : <GradesSection grading={grading} />}
    </AppLayout>
  );
}
