import AppLayout from '../Layouts/AppLayout';
import { Card, Empty, Grid, PageHeader, StatusBadge, Table, TextLink } from '../Components/ui';

export default function StudentHistory({ student, enrollments = [], documents = [], queue = [], clearances = [] }) {
  if (!student) {
    return (
      <AppLayout title="Request History">
        <PageHeader title="Request history" />
        <Empty icon="user" title="No student profile">This account has no student profile, so there is no history to show.</Empty>
      </AppLayout>
    );
  }

  return (
    <AppLayout title="Request History">
      <PageHeader back={{ href: '/student', label: 'Dashboard' }} title="Request history" description="Everything you have submitted, newest first." />
      <Grid cols="lg:grid-cols-2">
        <Card title="Enrollment requests">
          {enrollments.length === 0 ? (
            <Empty icon="academic">No enrollment requests yet.</Empty>
          ) : (
            <Table
              cols={['Term', 'Units', 'Status', 'Submitted', '']}
              rows={enrollments.map((e) => [
                e.term_label, e.units,
                <StatusBadge key="s" status={e.status} label={e.status_label} />,
                e.submitted_at ?? '—',
                <TextLink key="v" href={e.url}>View</TextLink>,
              ])}
            />
          )}
        </Card>

        <Card title="Document requests">
          {documents.length === 0 ? (
            <Empty icon="file">No document requests yet.</Empty>
          ) : (
            <Table
              cols={['Ref No.', 'Document', 'Status', 'Requested']}
              rows={documents.map((d) => [
                <TextLink key="r" href={d.url}>{d.reference_no}</TextLink>,
                d.document,
                <StatusBadge key="s" status={d.status} label={d.status_label} />,
                d.requested_at,
              ])}
            />
          )}
        </Card>

        <Card title="Queue history">
          {queue.length === 0 ? (
            <Empty icon="ticket">You have not taken a queue number yet.</Empty>
          ) : (
            <Table
              cols={['Number', 'Service', 'Date', 'Status']}
              rows={queue.map((q) => [q.code, q.service_label, q.date, <StatusBadge key="s" status={q.status} label={q.status_label} />])}
            />
          )}
        </Card>

        <Card title="Clearance history">
          {clearances.length === 0 ? (
            <Empty icon="checklist">No clearance records yet.</Empty>
          ) : (
            <Table
              cols={['Term', 'Progress', 'Status', 'Cleared']}
              rows={clearances.map((c) => [c.term_label, c.progress, <StatusBadge key="s" status={c.status} label={c.status_label} />, c.cleared_at ?? '—'])}
            />
          )}
        </Card>
      </Grid>
    </AppLayout>
  );
}
