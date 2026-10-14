import { Icon } from '../Components/ui';

const MESSAGES = {
  403: ['Access denied', 'You do not have permission to view this page.'],
  404: ['Not found', 'That page or record does not exist.'],
  500: ['Something went wrong', 'The server hit a problem. Please try again in a moment.'],
  503: ['Temporarily unavailable', 'The system is down for maintenance. Please try again soon.'],
};

// Standalone on purpose: error pages can render outside the normal layout/session.
export default function Error({ status }) {
  const [title, text] = MESSAGES[status] ?? ['Error', 'Something went wrong.'];
  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
      <div className="max-w-md rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <span className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-700"><Icon name="alert" className="h-6 w-6" /></span>
        <p className="text-4xl font-bold text-slate-900">{status}</p>
        <h1 className="mt-1 text-lg font-semibold text-slate-900">{title}</h1>
        <p className="mt-2 text-sm text-slate-600">{text}</p>
        <a href="/" className="mt-5 inline-flex items-center justify-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">Go to my portal</a>
      </div>
    </div>
  );
}
