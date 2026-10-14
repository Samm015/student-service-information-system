import { router } from '@inertiajs/react';
import {
  Button,
  Card,
  Empty,
  FilterBar,
  Pager,
  SearchInput,
  StatusBadge,
  Table,
  Tabs,
  useDialogs,
} from './ui';
import { useListFilters } from './useListFilters';

export default function ClearanceSignoff({
  clearance,
  basePath,
  officeLabel = 'your office',
}) {
  const initialFilters = clearance?.filters ?? {
    tab: 'open',
    q: '',
  };

  const [filters, go, goPage, loading] = useListFilters(
    basePath,
    initialFilters,
    {
      only: ['clearance'],
      defaults: { tab: 'open' },
    }
  );

  const { confirm, askReason, dialogs } = useDialogs();

  if (!clearance) {
    return (
      <Card title="Clearance sign-off">
        <Empty icon="building" title="No office assigned">
          Ask the administrator to assign your account to the
          appropriate office before processing clearance.
        </Empty>
      </Card>
    );
  }

  const act = async (row, status) => {
    if (status === 'signed') {
      const sure = await confirm({
        title: `Sign "${row.requirement}"?`,
        message:
          `${row.student} (${row.student_no}) will be marked as ` +
          `cleared by ${officeLabel}. This action is final.`,
        confirmLabel: 'Sign',
      });

      if (sure) {
        router.patch(
          row.update_url,
          { status: 'signed' },
          { preserveScroll: true }
        );
      }

      return;
    }

    const reason = await askReason({
      title: `Reject "${row.requirement}"?`,
      message:
        'The student will see your reason. A rejected item cannot ' +
        'be signed again through this screen.',
      label: 'Reason',
      confirmLabel: 'Reject',
    });

    if (reason !== null && reason.trim()) {
      router.patch(
        row.update_url,
        { status: 'rejected', remarks: reason.trim() },
        { preserveScroll: true }
      );
    }
  };

  return (
    <Card
      title="Clearance sign-off"
      description={`Only requirements assigned to ${officeLabel} appear here.`}
    >
      <FilterBar>
        <Tabs
          tabs={[
            ['open', 'Needs action'],
            ['signed', 'Signed'],
            ['all', 'All'],
          ]}
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
        <Empty
          icon="checklist"
          title="No clearance requirements match"
        >
          {filters.tab === 'open'
            ? 'Nothing is waiting for your signature.'
            : 'Try another tab or search.'}
        </Empty>
      ) : (
        <Table
          loading={loading}
          cols={[
            'Student',
            'Requirement',
            'Term',
            'Status',
            'Signed / remarks',
            '',
          ]}
          rows={clearance.rows.map((row) => [
            <span key="student">
              {row.student}{' '}
              <span className="text-slate-400">
                ({row.student_no})
              </span>
            </span>,
            row.requirement,
            row.term_label,
            <StatusBadge
              key="status"
              status={row.status}
              label={row.status_label}
            />,
            row.status === 'signed'
              ? row.signed_at
              : row.remarks ?? '—',
            <div
              key="actions"
              className="flex flex-wrap justify-end gap-2"
            >
              {row.can_sign && (
                <Button
                  size="sm"
                  onClick={() => act(row, 'signed')}
                >
                  Sign
                </Button>
              )}

              {row.can_reject && (
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => act(row, 'rejected')}
                >
                  Reject
                </Button>
              )}
            </div>,
          ])}
        />
      )}

      <Pager pager={clearance.pager} onGo={goPage} />
      {dialogs}
    </Card>
  );
}
