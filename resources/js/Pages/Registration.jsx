import AppLayout from '../Layouts/AppLayout';
import { Card, Empty, FilterBar, Grid, LinkButton, PageHeader, Pager, SearchInput, StatusBadge, SummaryCard, Table, Tabs, TextLink } from '../Components/ui';
import { QueueManager } from '../Components/QueuePanel';
import { useListFilters } from '../Components/useListFilters';
import { usePolling } from '../Components/usePolling';
import ClearanceSignoff from '../Components/ClearanceSignoff';

export default function Registration({
  stats,
  queue,
  enrollments,
  documents,
  clearance,
}) {
  // The queue and the counters can be changed by someone else at the next window: keep them fresh.
  const live = usePolling(['queue', 'stats', 'enrollments', 'documents'], { interval: 5000 });
  const [filters, go, goPage, loading] = useListFilters('/registration', enrollments.filters, {
    only: ['enrollments'],
    defaults: { tab: 'pending' },
  });

  return (
    <AppLayout title="Registration Portal">
      <PageHeader title="Registration" description="Review enrollments, process document requests and run the service window." />

      <Grid cols="sm:grid-cols-2 xl:grid-cols-4">
        <SummaryCard icon="academic" label="Pending enrollments" value={stats.pending} tone="warning" />
        <SummaryCard icon="file" label="Documents to process" value={stats.documents_to_do} tone="brand" />
        <SummaryCard icon="ticket" label="Waiting queue" value={stats.in_queue} tone="brand" hint={`Now serving: ${stats.now_serving}`} />
        <SummaryCard icon="check" label="Completed today" value={stats.completed_today} tone="success" hint={stats.completed_hint} />
      </Grid>

      <QueueManager queue={queue} live={live} title="Queue management" />
      <ClearanceSignoff
        clearance={clearance}
        basePath="/registration"
        officeLabel="the Registrar's office"
      />
      <Card title="Enrollment review" description="Open an application to see the subjects and approve or reject it.">
        <FilterBar>
          <Tabs
            tabs={[['pending', 'Pending'], ['processed', 'Processed'], ['all', 'All']]}
            value={filters.tab}
            onChange={(tab) => go({ ...filters, tab }, true)}
          />
          <SearchInput
            className="sm:w-72"
            label="Search enrollment applications"
            placeholder="Search student no. or name"
            defaultValue={filters.q}
            onChange={(e) => go({ ...filters, q: e.target.value })}
          />
        </FilterBar>
        {enrollments.rows.length === 0 ? (
          <Empty icon="academic" title="No enrollment requests match">{filters.tab === 'pending' ? 'There is nothing waiting for review.' : 'Try another tab or search.'}</Empty>
        ) : (
          <Table
            loading={loading}
            cols={['Student No.', 'Name', 'Program', 'Term', 'Units', 'Status', '']}
            rows={enrollments.rows.map((r) => [
              r.student_no,
              <strong key="n" className="text-slate-900">{r.name}</strong>,
              r.program,
              r.term_label,
              r.units,
              <StatusBadge key="s" status={r.status} label={r.status_label} />,
              <LinkButton key="a" href={r.url} size="sm" variant={r.status === 'pending' ? 'primary' : 'secondary'}>{r.status === 'pending' ? 'Review' : 'View'}</LinkButton>,
            ])}
          />
        )}
        <Pager pager={enrollments.pager} onGo={goPage} />
      </Card>

      <Card
        title="Document processing"
        description="The oldest open requests first."
        action={<LinkButton href="/documents" size="sm">All document requests</LinkButton>}
      >
        {documents.rows.length === 0 ? (
          <Empty icon="file" title="No pending documents">Nothing is waiting to be processed.</Empty>
        ) : (
          <>
            <Table
              cols={['Ref No.', 'Student', 'Document', 'Payment', 'Status', 'Expected release', '']}
              rows={documents.rows.map((r) => [
                <TextLink key="r" href={r.url}>{r.reference_no}</TextLink>,
                `${r.student} (${r.student_no})`,
                `${r.document} × ${r.copies}`,
                r.payment,
                <StatusBadge key="s" status={r.status} label={r.status_label} />,
                r.expected_release ?? <span key="e" className="text-slate-400">Not set</span>,
                <LinkButton key="o" href={r.url} size="sm" variant="primary">Open</LinkButton>,
              ])}
            />
            {documents.total > documents.rows.length && (
              <p className="mt-3 text-sm text-slate-500">Showing the first {documents.rows.length} of {documents.total}. <TextLink href="/documents">See all</TextLink></p>
            )}
          </>
        )}
      </Card>
    </AppLayout>
  );
}
