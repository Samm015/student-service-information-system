import { useEffect, useId, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';

/* ==========================================================================
 * Student Services - shared UI components (the design system).
 *
 * Rules the whole app follows:
 *  - Indigo ("brand") = actions, active navigation, selected state, links.
 *  - Green / amber / red / slate = STATUS only (see StatusBadge).
 *  - Cards are white with a thin slate border. Colour is never decoration.
 *  - A status always looks the same everywhere (one map, one component).
 * ========================================================================== */

/* ---------- Icons (inline SVG, no extra package) --------------------------- */

const ICONS = {
  home: <><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><path d="M9 22V12h6v10" /></>,
  academic: <><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></>,
  checklist: <><path d="M9 11l3 3L22 4" /><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" /></>,
  chart: <path d="M18 20V10M12 20V4M6 20v-6" />,
  file: <><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" /></>,
  ticket: <><path d="M3 9a2 2 0 0 0 0 6v3a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-3a2 2 0 0 1 0-6V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1z" /><path d="M13 5v2M13 11v2M13 17v2" /></>,
  payment: <><rect x="1" y="4" width="22" height="16" rx="2" /><path d="M1 10h22" /></>,
  building: <><path d="M3 21h18M5 21V7l7-4 7 4v14" /><path d="M9 9h1M14 9h1M9 13h1M14 13h1M10 21v-4h4v4" /></>,
  users: <><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></>,
  user: <><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></>,
  shield: <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />,
  book: <><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></>,
  clock: <><circle cx="12" cy="12" r="10" /><path d="M12 6v6l4 2" /></>,
  calendar: <><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></>,
  search: <><circle cx="11" cy="11" r="8" /><path d="M21 21l-4.35-4.35" /></>,
  plus: <path d="M12 5v14M5 12h14" />,
  edit: <><path d="M12 20h9" /><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z" /></>,
  check: <path d="M20 6L9 17l-5-5" />,
  x: <path d="M18 6L6 18M6 6l12 12" />,
  minus: <path d="M5 12h14" />,
  dot: <circle cx="12" cy="12" r="4" />,
  alert: <><circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" /></>,
  info: <><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></>,
  chevron: <path d="M6 9l6 6 6-6" />,
  menu: <path d="M3 12h18M3 6h18M3 18h18" />,
  logout: <><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="M16 17l5-5-5-5M21 12H9" /></>,
  arrow: <path d="M5 12h14M12 5l7 7-7 7" />,
  refresh: <><path d="M23 4v6h-6M1 20v-6h6" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /></>,
};

export function Icon({ name, className = 'h-5 w-5' }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={`shrink-0 ${className}`}
      aria-hidden="true"
    >
      {ICONS[name] ?? ICONS.dot}
    </svg>
  );
}

/* ---------- Status language ------------------------------------------------
 * One map for every module (enrollment, documents, payments, clearance, queue).
 * Each tone has a colour AND an icon, and the text label is always shown, so meaning
 * never depends on colour alone.
 */

export const TONES = {
  success: { chip: 'bg-emerald-50 text-emerald-800 ring-emerald-600/20', dot: 'bg-emerald-500', icon: 'check' },
  warning: { chip: 'bg-amber-50 text-amber-800 ring-amber-600/25', dot: 'bg-amber-500', icon: 'clock' },
  danger: { chip: 'bg-red-50 text-red-800 ring-red-600/20', dot: 'bg-red-500', icon: 'x' },
  info: { chip: 'bg-brand-50 text-brand-800 ring-brand-600/20', dot: 'bg-brand-500', icon: 'dot' },
  neutral: { chip: 'bg-slate-100 text-slate-700 ring-slate-500/20', dot: 'bg-slate-400', icon: 'minus' },
};

const STATUS_TONE = {
  // Green: done / approved / paid / released / cleared
  approved: 'success', signed: 'success', cleared: 'success', completed: 'success',
  ready: 'success', released: 'success', paid: 'success', active: 'success',
  // Amber: waiting / needs attention
  pending: 'warning', waiting: 'warning', awaiting_payment: 'warning', open: 'warning',
  // Indigo: in progress right now
  processing: 'info', serving: 'info', called: 'info',
  // Red: rejected / failed
  rejected: 'danger', failed: 'danger', overdue: 'danger',
  // Slate: closed without a result
  cancelled: 'neutral', skipped: 'neutral', void: 'neutral', inactive: 'neutral', none: 'neutral',
};

const STATUS_LABEL = { awaiting_payment: 'Awaiting payment', ready: 'Ready for release', void: 'Void' };

export const toneOf = (status) => STATUS_TONE[status] ?? 'neutral';

export function StatusBadge({ status, label, tone }) {
  const t = TONES[tone ?? toneOf(status)];
  const text = label ?? STATUS_LABEL[status] ?? String(status ?? '').replace(/_/g, ' ');
  return (
    <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset first-letter:uppercase ${t.chip}`}>
      <Icon name={t.icon} className="h-3 w-3" />
      {text}
    </span>
  );
}

/* ---------- Buttons & links ------------------------------------------------ */

const BTN = {
  primary: 'bg-brand-600 text-white shadow-sm hover:bg-brand-700 disabled:hover:bg-brand-600',
  secondary: 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 shadow-sm hover:bg-slate-50 disabled:hover:bg-white',
  danger: 'bg-red-600 text-white shadow-sm hover:bg-red-700 disabled:hover:bg-red-600',
  ghost: 'text-slate-600 hover:bg-slate-100 disabled:hover:bg-transparent',
};
const BTN_SIZE = { sm: 'px-2.5 py-1 text-xs', md: 'px-3.5 py-2 text-sm' };
const btnClass = (variant, size, extra = '') =>
  `inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${BTN[variant]} ${BTN_SIZE[size]} ${extra}`;

export function Spinner({ className = 'h-4 w-4' }) {
  return (
    <svg className={`animate-spin ${className}`} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3" className="opacity-25" />
      <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  );
}

// `loading` shows a spinner and disables the button, so a double-click cannot submit twice.
export function Button({ variant = 'primary', size = 'md', type = 'button', loading = false, disabled, icon, className = '', children, ...p }) {
  return (
    <button type={type} {...p} disabled={disabled || loading} aria-busy={loading || undefined} className={btnClass(variant, size, className)}>
      {loading ? <Spinner /> : icon ? <Icon name={icon} className="h-4 w-4" /> : null}
      {children}
    </button>
  );
}

export function LinkButton({ href, variant = 'secondary', size = 'md', icon, className = '', children }) {
  return (
    <Link href={href} className={btnClass(variant, size, className)}>
      {icon && <Icon name={icon} className="h-4 w-4" />}
      {children}
    </Link>
  );
}

export const TextLink = ({ href, children }) => (
  <Link href={href} className="font-medium text-brand-700 underline-offset-2 hover:underline">{children}</Link>
);

/* ---------- Cards & headers ------------------------------------------------ */

export function Card({ title, description, action, children, className = '', bodyClassName = '' }) {
  return (
    <section className={`rounded-xl border border-slate-200 bg-white shadow-sm ${className}`}>
      {(title || action) && (
        <header className="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 px-5 py-3.5">
          <div>
            {title && <h2 className="text-base font-semibold text-slate-900">{title}</h2>}
            {description && <p className="mt-0.5 text-sm text-slate-500">{description}</p>}
          </div>
          {action}
        </header>
      )}
      <div className={`p-5 ${bodyClassName}`}>{children}</div>
    </section>
  );
}

// Old name kept so nothing that still imports it breaks.
export const Widget = Card;

export function PageHeader({ title, description, actions, back }) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-3">
      <div className="min-w-0">
        {back && (
          <Link href={back.href} className="mb-1 inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
            <Icon name="arrow" className="h-3.5 w-3.5 rotate-180" /> {back.label}
          </Link>
        )}
        <h1 className="text-2xl font-bold tracking-tight text-slate-900">{title}</h1>
        {description && <p className="mt-1 text-sm text-slate-600">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function SectionHeader({ title, description, action }) {
  return (
    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
      <div>
        <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-500">{title}</h3>
        {description && <p className="text-sm text-slate-500">{description}</p>}
      </div>
      {action}
    </div>
  );
}

const ICON_TILE = {
  brand: 'bg-brand-50 text-brand-700',
  success: 'bg-emerald-50 text-emerald-700',
  warning: 'bg-amber-50 text-amber-700',
  danger: 'bg-red-50 text-red-700',
  neutral: 'bg-slate-100 text-slate-600',
};

export function SummaryCard({ label, value, hint, icon = 'chart', tone = 'brand' }) {
  return (
    <div className="flex items-start gap-2.5 rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:gap-3 sm:p-4">
      <span className={`flex h-9 w-9 shrink-0 sm:h-10 sm:w-10 items-center justify-center rounded-lg ${ICON_TILE[tone]}`}>
        <Icon name={icon} />
      </span>
      <div className="min-w-0">
        <p className="text-sm text-slate-500">{label}</p>
        <p className="truncate text-2xl font-bold leading-tight text-slate-900">{value}</p>
        {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
      </div>
    </div>
  );
}

// Old name kept: <Stat label value /> still works.
export const Stat = SummaryCard;

export const Grid = ({ children, cols = 'sm:grid-cols-2 xl:grid-cols-4', className = '' }) => (
  <div className={`grid grid-cols-1 gap-3 sm:gap-4 ${cols} ${className}`}>{children}</div>
);

/**
 * Collapsible card. The header must stay useful when closed:
 *   <CollapsibleCard icon="ticket" title="Queue" summary="A-024 · 3 people ahead" badge={<StatusBadge .../>}>
 * `attention` adds a small amber dot so the student sees what needs them.
 */
export function CollapsibleCard({ icon, title, summary, badge, defaultOpen = false, attention = false, children }) {
  const [open, setOpen] = useState(defaultOpen);
  const panelId = useId();
  const body = useRef(null);

  // `inert` keeps hidden content out of the tab order (set on the DOM node: works on React 18 and 19).
  useEffect(() => {
    if (body.current) body.current.inert = !open;
  }, [open]);

  return (
    <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
      <h2>
        <button
          type="button"
          aria-expanded={open}
          aria-controls={panelId}
          onClick={() => setOpen(!open)}
          className="flex w-full items-center gap-3 rounded-xl px-4 py-3.5 text-left hover:bg-slate-50 sm:px-5"
        >
          <span className="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
            <Icon name={icon} />
            {attention && <span className="absolute -right-1 -top-1 h-3 w-3 rounded-full bg-amber-500 ring-2 ring-white" title="Needs your attention" />}
          </span>
          <span className="min-w-0 flex-1">
            <span className="block truncate text-base font-semibold text-slate-900">
              {title}
              {summary && <span className="font-medium text-slate-600"> <span className="text-slate-300">·</span> {summary}</span>}
            </span>
          </span>
          {badge && <span className="hidden shrink-0 sm:block">{badge}</span>}
          <Icon name="chevron" className={`h-5 w-5 text-slate-400 transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
        </button>
      </h2>
      <div
        ref={body}
        id={panelId}
        aria-hidden={!open}
        className={`grid transition-[grid-template-rows] duration-200 ease-out ${open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'}`}
      >
        <div className="overflow-hidden">
          <div className="border-t border-slate-100 px-4 py-4 sm:px-5">{children}</div>
        </div>
      </div>
    </section>
  );
}

/* ---------- Feedback: notice, empty / loading / error, progress ------------ */

const NOTICE = {
  success: ['border-emerald-200 bg-emerald-50 text-emerald-900', 'check'],
  error: ['border-red-200 bg-red-50 text-red-900', 'alert'],
  warning: ['border-amber-200 bg-amber-50 text-amber-900', 'alert'],
  info: ['border-slate-200 bg-white text-slate-800', 'info'],
};

export function Notice({ kind = 'info', children, onDismiss }) {
  const [cls, icon] = NOTICE[kind];
  return (
    <div role={kind === 'error' ? 'alert' : 'status'} className={`flex items-start gap-2.5 rounded-lg border px-4 py-3 text-sm ${cls}`}>
      <Icon name={icon} className="mt-0.5 h-4 w-4" />
      <div className="flex-1">{children}</div>
      {onDismiss && (
        <button type="button" onClick={onDismiss} aria-label="Dismiss message" className="rounded p-0.5 opacity-70 hover:opacity-100">
          <Icon name="x" className="h-4 w-4" />
        </button>
      )}
    </div>
  );
}

// `<Empty>No pending documents.</Empty>`, or with a title / action.
export function Empty({ icon = 'info', title, children, action }) {
  return (
    <div className="flex flex-col items-center rounded-lg border border-dashed border-slate-300 bg-slate-50/60 px-4 py-8 text-center">
      <span className="mb-2 flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-500"><Icon name={icon} className="h-4 w-4" /></span>
      {title && <p className="text-sm font-semibold text-slate-800">{title}</p>}
      <p className="text-sm text-slate-500">{children}</p>
      {action && <div className="mt-3">{action}</div>}
    </div>
  );
}

export function LoadingState({ children = 'Loading…' }) {
  return (
    <div role="status" className="flex items-center justify-center gap-2 py-6 text-sm text-slate-500">
      <Spinner className="h-4 w-4 text-brand-600" /> {children}
    </div>
  );
}

export function ErrorState({ title = 'Something went wrong', children, onRetry }) {
  return (
    <div role="alert" className="flex flex-col items-center rounded-lg border border-red-200 bg-red-50/60 px-4 py-8 text-center">
      <span className="mb-2 flex h-9 w-9 items-center justify-center rounded-full bg-red-100 text-red-700"><Icon name="alert" className="h-4 w-4" /></span>
      <p className="text-sm font-semibold text-red-900">{title}</p>
      {children && <p className="text-sm text-red-800">{children}</p>}
      {onRetry && <div className="mt-3"><Button variant="secondary" size="sm" icon="refresh" onClick={onRetry}>Try again</Button></div>}
    </div>
  );
}

export function ProgressBar({ value, max, label }) {
  const pct = max > 0 ? Math.round((value / max) * 100) : 0;
  return (
    <div>
      <div className="mb-1 flex justify-between text-xs text-slate-600">
        <span>{label}</span><span className="font-semibold">{value} of {max}</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow={value} aria-valuemin={0} aria-valuemax={max} aria-label={label}>
        <div className="h-full rounded-full bg-emerald-500 transition-all duration-300" style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

/* ---------- Timeline & step tracker ---------------------------------------- */

// Vertical history. items: [{ status, status_label, at, remarks }]
export function Timeline({ items }) {
  if (!items.length) return <Empty>No history yet.</Empty>;
  return (
    <ol className="relative space-y-4 border-l border-slate-200 pl-5">
      {items.map((t, i) => (
        <li key={i} className="relative">
          <span className={`absolute -left-[26px] top-1 h-3 w-3 rounded-full ring-4 ring-white ${TONES[toneOf(t.status)].dot}`} />
          <p className="text-sm font-semibold text-slate-900">{t.status_label}</p>
          <p className="text-xs text-slate-500">{t.at}</p>
          {t.remarks && <p className="mt-0.5 text-sm text-slate-600">{t.remarks}</p>}
        </li>
      ))}
    </ol>
  );
}

// Horizontal progress for a request: Submitted -> Payment -> Processing -> Ready -> Released.
// tracker: { steps: [{ value, label }], current: status value }
export function StepTracker({ tracker }) {
  if (!tracker) return null;
  const { steps, current } = tracker;
  const idx = steps.findIndex((s) => s.value === current);
  return (
    <ol className="flex items-start" aria-label="Request progress">
      {steps.map((s, i) => {
        const done = idx >= 0 && (i < idx || current === 'released');
        const now = i === idx && current !== 'released';
        return (
          <li key={s.value} className="relative flex-1 text-center" aria-current={now ? 'step' : undefined}>
            {i > 0 && <span className={`absolute right-1/2 top-3 h-0.5 w-full ${i <= idx ? 'bg-emerald-400' : 'bg-slate-200'}`} />}
            <span className={`relative mx-auto flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold ring-4 ring-white ${
              done ? 'bg-emerald-500 text-white' : now ? 'bg-brand-600 text-white' : 'bg-slate-200 text-slate-500'}`}>
              {done ? <Icon name="check" className="h-3.5 w-3.5" /> : i + 1}
            </span>
            <span className={`mt-1 block text-[11px] leading-tight sm:text-xs ${now ? 'font-semibold text-slate-900' : 'text-slate-500'}`}>{s.label}</span>
          </li>
        );
      })}
    </ol>
  );
}

/* ---------- Forms ---------------------------------------------------------- */

export function Field({ label, error, hint, required, children }) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-slate-700">
        {label}
        {required && <span className="ml-0.5 text-red-600" aria-hidden="true">*</span>}
        {required && <span className="sr-only"> (required)</span>}
      </span>
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
      {error && (
        <span role="alert" className="mt-1 flex items-start gap-1 text-xs font-medium text-red-700">
          <Icon name="alert" className="mt-px h-3.5 w-3.5" /> {error}
        </span>
      )}
    </label>
  );
}

const inputCls =
  'block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500';

const FILTERS = {
  text: (v) => v.replace(/[^\p{L}\s.,'’-]/gu, ''),          // letters only (no digits)
  number: (v) => {                                          // digits + one decimal point
    const [a, ...b] = v.replace(/[^\d.]/g, '').split('.');
    return b.length ? a + '.' + b.join('') : a;
  },
  id: (v) => v.replace(/[^\d-]/g, ''),                      // digits and dash, e.g. 2024-00001
  code: (v) => v.replace(/[^A-Za-z0-9-]/g, '').toUpperCase(), // short codes: CCS, ITP101, DR-1
  any: (v) => v,                                            // search boxes, free text
};
const NATIVE = ['email', 'password', 'date', 'time'];
const COLLAPSED = 40;

// <Input kind="text|number|id|code|any">: filters what you type (see README). Long text collapses to one line.
function SmartInput({ kind = 'text', type, min, max, step, value, defaultValue = '', onChange, ...rest }) {
  const clean = FILTERS[kind] ?? FILTERS.text;
  const [v, setV] = useState(clean(String(value ?? defaultValue)));
  const [open, setOpen] = useState(false);
  const [long, setLong] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    el.style.height = 'auto';
    const full = el.scrollHeight;
    setLong(full > COLLAPSED + 4);
    el.style.height = (open ? full : COLLAPSED) + 'px';
  }, [v, open]);

  return (
    <div>
      <textarea
        ref={ref}
        rows={1}
        {...rest}
        value={v}
        inputMode={kind === 'number' || kind === 'id' ? 'decimal' : 'text'}
        onChange={(e) => {
          const c = clean(e.target.value);
          setV(c);
          onChange?.({ target: { value: c } });
        }}
        onKeyDown={(e) => e.key === 'Enter' && e.preventDefault()}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        className={`${inputCls} resize-none overflow-hidden`}
      />
      {long && (
        <button
          type="button"
          onMouseDown={(e) => e.preventDefault()}
          onClick={() => setOpen(!open)}
          className="mt-1 text-xs font-medium text-brand-700 hover:underline"
        >
          {open ? 'Show less' : 'Show more'}
        </button>
      )}
    </div>
  );
}

export const Input = (props) =>
  NATIVE.includes(props.type)
    ? <input {...props} className={inputCls} />
    : <SmartInput {...props} />;

// Multi-line text (reasons, remarks). Unlike Input it is always fully visible.
export const Textarea = ({ rows = 3, ...p }) => <textarea rows={rows} {...p} className={`${inputCls} resize-y`} />;

export const Select = ({ options, ...p }) => (
  <select {...p} className={inputCls}>
    {options.map((o) => {
      // Options can be plain strings or { value, label }.
      const opt = typeof o === 'object' ? o : { value: o, label: o };
      return <option key={opt.value} value={opt.value}>{opt.label}</option>;
    })}
  </select>
);

export function SearchInput({ label = 'Search', className = '', ...p }) {
  return (
    <div className={`relative ${className}`}>
      <Icon name="search" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
      <input type="search" aria-label={label} {...p} className={`${inputCls} pl-9`} />
    </div>
  );
}

/* ---------- Tabs, filters, pager, table ------------------------------------ */

// Segmented control that switches a list between views. tabs: [[value, label, count?]]
export const Tabs = ({ tabs, value, onChange }) => (
  <div className="inline-flex max-w-full flex-wrap gap-1 rounded-lg bg-slate-100 p-1" role="tablist">
    {tabs.map(([v, label, count]) => (
      <button
        key={v}
        type="button"
        role="tab"
        aria-selected={value === v}
        onClick={() => onChange(v)}
        className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
          value === v ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
      >
        {label}
        {count !== undefined && <span className="ml-1.5 rounded-full bg-slate-200/70 px-1.5 text-xs">{count}</span>}
      </button>
    ))}
  </div>
);

// Search + tabs on one row. Children (extra filters) go on the right.
export function FilterBar({ children }) {
  return <div className="mb-3 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">{children}</div>;
}

// "Showing 1-10 of 23" with Previous / Next. `onGo(url)` loads that page.
export const Pager = ({ pager, onGo }) => {
  if (!pager.total) return null;
  const multiple = pager.prev || pager.next;

  return (
    <div className="mt-3 flex items-center justify-between gap-2 text-sm">
      <span className="text-slate-500">Showing {pager.from}–{pager.to} of {pager.total}</span>
      {multiple && (
        <span className="flex gap-2">
          <Button variant="secondary" size="sm" disabled={!pager.prev} onClick={() => onGo(pager.prev)}>Previous</Button>
          <Button variant="secondary" size="sm" disabled={!pager.next} onClick={() => onGo(pager.next)}>Next</Button>
        </span>
      )}
    </div>
  );
};

/**
 * Data table. cols = ['Name', 'Status', ''] and rows = [[cell, cell, cell]].
 * An empty column title marks the actions column.
 * On small screens each row turns into a card (label: value), so nothing needs sideways scrolling.
 */
export function Table({ cols, rows, loading = false }) {
  return (
    <div className={`transition-opacity ${loading ? 'opacity-60' : ''}`} aria-busy={loading || undefined}>
      <div className="hidden overflow-x-auto rounded-lg border border-slate-200 md:block">
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
            <tr>
              {cols.map((c, i) => <th key={i} scope="col" className="whitespace-nowrap px-4 py-2.5">{c}</th>)}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((r, i) => (
              <tr key={i} className="hover:bg-slate-50/70">
                {r.map((c, j) => (
                  <td key={j} className={`px-4 py-3 align-middle text-slate-700 ${cols[j] === '' ? 'text-right' : ''}`}>{c}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <ul className="space-y-3 md:hidden">
        {rows.map((r, i) => (
          <li key={i} className="rounded-lg border border-slate-200 p-3">
            <dl className="space-y-1.5 text-sm">
              {r.map((c, j) => cols[j] !== '' && (
                <div key={j} className="flex justify-between gap-3">
                  <dt className="shrink-0 text-slate-500">{cols[j]}</dt>
                  <dd className="min-w-0 break-words text-right text-slate-800">{c}</dd>
                </div>
              ))}
            </dl>
            {r.some((c, j) => cols[j] === '' && c) && (
              <div className="mt-3 flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-3">
                {r.map((c, j) => cols[j] === '' && <span key={j}>{c}</span>)}
              </div>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}

// Label / value pairs for a detail page.
export function DetailList({ items }) {
  return (
    <dl className="divide-y divide-slate-100 text-sm">
      {items.map(([label, value]) => (
        <div key={label} className="flex gap-3 py-2">
          <dt className="w-36 shrink-0 text-slate-500">{label}</dt>
          <dd className="min-w-0 break-words text-slate-900">{value}</dd>
        </div>
      ))}
    </dl>
  );
}

/* ---------- Modal & confirmation dialogs ----------------------------------- */

const FOCUSABLE = 'a[href],button:not([disabled]),textarea,input,select,[tabindex]:not([tabindex="-1"])';

export function Modal({ open, onClose, title, children, footer, size = 'md' }) {
  const box = useRef(null);
  const titleId = useId();

  useEffect(() => {
    if (!open) return undefined;
    const previous = document.activeElement;
    const { overflow } = document.body.style;
    document.body.style.overflow = 'hidden';
    box.current?.querySelector(FOCUSABLE)?.focus();

    const onKey = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); onClose?.(); }
      if (e.key !== 'Tab') return;
      const items = [...box.current.querySelectorAll(FOCUSABLE)].filter((el) => !el.disabled);
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = overflow;
      previous?.focus?.();
    };
  }, [open]);

  if (!open) return null;
  const width = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl' }[size];

  return (
    <div className="ui-fade fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-4 sm:items-center" onMouseDown={(e) => e.target === e.currentTarget && onClose?.()}>
      <div ref={box} role="dialog" aria-modal="true" aria-labelledby={titleId} className={`ui-pop max-h-[90vh] w-full overflow-y-auto rounded-xl bg-white shadow-xl ${width}`}>
        <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5">
          <h2 id={titleId} className="text-base font-semibold text-slate-900">{title}</h2>
          <button type="button" onClick={onClose} aria-label="Close" className="rounded p-1 text-slate-500 hover:bg-slate-100"><Icon name="x" className="h-4 w-4" /></button>
        </div>
        <div className="px-5 py-4">{children}</div>
        {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 bg-slate-50 px-5 py-3 sm:rounded-b-xl">{footer}</div>}
      </div>
    </div>
  );
}

export function ConfirmDialog({ open, title, message, confirmLabel = 'Confirm', variant = 'primary', loading = false, onConfirm, onCancel }) {
  return (
    <Modal
      open={open}
      onClose={onCancel}
      title={title}
      size="sm"
      footer={<>
        <Button variant="secondary" onClick={onCancel}>Cancel</Button>
        <Button variant={variant} loading={loading} onClick={onConfirm}>{confirmLabel}</Button>
      </>}
    >
      <p className="text-sm text-slate-600">{message}</p>
    </Modal>
  );
}

// Asks for a written reason (reject, void). The server validates it again.
export function ReasonDialog({ open, title, message, label = 'Reason', confirmLabel = 'Confirm', variant = 'danger', minLength = 3, onSubmit, onCancel }) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');

  useEffect(() => { if (open) { setReason(''); setError(''); } }, [open]);

  const submit = () => {
    if (reason.trim().length < minLength) {
      setError(`Please write at least ${minLength} characters.`);
      return;
    }
    onSubmit(reason.trim());
  };

  return (
    <Modal
      open={open}
      onClose={onCancel}
      title={title}
      footer={<>
        <Button variant="secondary" onClick={onCancel}>Cancel</Button>
        <Button variant={variant} onClick={submit}>{confirmLabel}</Button>
      </>}
    >
      {message && <p className="mb-3 text-sm text-slate-600">{message}</p>}
      <Field label={label} required error={error}>
        <Textarea value={reason} onChange={(e) => { setReason(e.target.value); setError(''); }} maxLength={500} />
      </Field>
    </Modal>
  );
}

/**
 * Promise-style dialogs, so a click handler can simply await the answer:
 *
 *   const { confirm, askReason, dialogs } = useDialogs();
 *   if (await confirm({ title: 'Skip A-004?', confirmLabel: 'Skip' })) router.patch(...)
 *   const reason = await askReason({ title: 'Reject request' });   // string, or null if cancelled
 *   ...
 *   return <>{...page...}{dialogs}</>;
 */
export function useDialogs() {
  const [state, setState] = useState(null);

  const ask = (opts) => new Promise((resolve) => setState({ ...opts, resolve }));
  const close = (value) => { state?.resolve(value); setState(null); };

  const dialogs = state && (state.reason ? (
    <ReasonDialog open {...state} onSubmit={(r) => close(r)} onCancel={() => close(null)} />
  ) : (
    <ConfirmDialog open {...state} onConfirm={() => close(true)} onCancel={() => close(false)} />
  ));

  return {
    confirm: (opts) => ask(opts),
    askReason: (opts) => ask({ ...opts, reason: true }),
    dialogs,
  };
}

/* ---------- Queue ticket (what a student sees) ------------------------------ */

export function QueueTicket({ entry, action }) {
  const called = entry.status === 'serving';
  return (
    <div className={`overflow-hidden rounded-xl border ${called ? 'border-brand-300 ring-2 ring-brand-100' : 'border-slate-200'}`}>
      {called && (
        <div className="flex items-center gap-2 bg-brand-600 px-4 py-2 text-sm font-semibold text-white">
          <Icon name="arrow" className="h-4 w-4" /> It’s your turn. Please proceed to {entry.counter ?? 'the counter'}.
        </div>
      )}
      <div className="grid gap-4 p-4 sm:grid-cols-[auto_1fr] sm:items-center">
        <div className="text-center sm:px-4">
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Your number</p>
          <p className="text-4xl font-extrabold tracking-tight text-brand-700">{entry.code}</p>
          <div className="mt-1"><StatusBadge status={entry.status} label={entry.status_label} /></div>
        </div>
        <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
          <div>
            <dt className="text-xs text-slate-500">Now serving</dt>
            <dd className="font-semibold text-slate-900">{entry.now_serving ?? '—'}</dd>
            {entry.now_serving_counter && <dd className="text-xs text-slate-500">{entry.now_serving_counter}</dd>}
          </div>
          <div>
            <dt className="text-xs text-slate-500">People ahead</dt>
            <dd className="font-semibold text-slate-900">{called ? 0 : entry.ahead}</dd>
          </div>
          <div>
            <dt className="text-xs text-slate-500">Service</dt>
            <dd className="font-semibold text-slate-900">{entry.service_label}</dd>
          </div>
          <div>
            <dt className="text-xs text-slate-500">Counter / window</dt>
            <dd className="font-semibold text-slate-900">{entry.counter ?? 'Not assigned yet'}</dd>
          </div>
        </dl>
      </div>
      {action && <div className="flex justify-end border-t border-slate-100 bg-slate-50 px-4 py-2">{action}</div>}
    </div>
  );
}

// "Live · updates automatically" / "Connection lost, retrying…" for polled panels.
export function LiveIndicator({ failed }) {
  return (
    <span className={`inline-flex items-center gap-1.5 text-xs ${failed ? 'font-medium text-red-700' : 'text-slate-500'}`} role="status">
      <span className={`h-2 w-2 rounded-full ${failed ? 'bg-red-500' : 'animate-pulse bg-emerald-500'}`} />
      {failed ? 'Connection lost, retrying…' : 'Live · updates automatically'}
    </span>
  );
}
