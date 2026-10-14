import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * Simple polling (no WebSockets): every few seconds ask the server for fresh values of
 * the named props only. The server stays the single source of truth for the queue.
 *
 *   const live = usePolling(['queue', 'stats'], { interval: 4000 });
 *   <LiveIndicator failed={live.failed} />
 *
 * - Pauses while the browser tab is hidden and refreshes the moment it is visible again.
 * - `async: true` means a background refresh never cancels (or is cancelled by) a button
 *   the user just pressed. Needs Inertia 2 or newer (the install scripts fetch the latest).
 * - preserveState keeps whatever the user is typing.
 */
export function usePolling(only, { interval = 4000, enabled = true } = {}) {
  const [status, setStatus] = useState({ failed: false });
  const busy = useRef(false);
  const key = only.join(',');

  useEffect(() => {
    if (!enabled) return undefined;

    const tick = () => {
      if (document.hidden || busy.current) return;
      busy.current = true;
      let ok = false;

      router.reload({
        only,
        async: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { ok = true; },
        onFinish: () => {
          busy.current = false;
          setStatus((s) => (s.failed === !ok ? s : { failed: !ok }));
        },
      });
    };

    const timer = setInterval(tick, interval);
    const onVisible = () => { if (!document.hidden) tick(); };
    document.addEventListener('visibilitychange', onVisible);

    return () => {
      clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [key, interval, enabled]);

  return status;
}
