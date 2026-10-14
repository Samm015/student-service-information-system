import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * Search + tab state for a server-side list.
 *   const [filters, go, goPage, loading] = useListFilters('/registration', props.filters, { only: ['enrollments'], defaults: { tab: 'pending' } });
 *   go({ ...filters, tab: 'all' }, true)   // switch tab now
 *   go({ ...filters, q: 'ana' })           // typing: waits 300 ms
 * The server does the real filtering; only the named prop is reloaded.
 * `loading` is true while a request is in flight (dim the table with it).
 */
export function useListFilters(path, initial, { only, defaults = {} } = {}) {
  const [filters, setFilters] = useState(initial);
  const [loading, setLoading] = useState(false);
  const timer = useRef(null);
  const options = {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only,
    onStart: () => setLoading(true),
    onFinish: () => setLoading(false),
  };

  const go = (next, immediate = false) => {
    setFilters(next);
    clearTimeout(timer.current);

    const query = Object.fromEntries(
      Object.entries(next).filter(([k, v]) => v !== '' && v != null && v !== defaults[k])
    );
    const run = () => router.get(path, query, options);

    if (immediate) run();
    else timer.current = setTimeout(run, 300);
  };

  const goPage = (url) => url && router.get(url, {}, options);

  return [filters, go, goPage, loading];
}
