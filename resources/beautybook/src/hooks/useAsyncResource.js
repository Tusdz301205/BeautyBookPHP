import { useCallback, useEffect, useRef, useState } from 'react';
import { createLatestRequest } from '../utils/latestRequest';

/** A key identifies all inputs to a read; a changed key never exposes old data. */
export function useAsyncResource(key, load) {
  const request = useRef(null);
  if (!request.current) request.current = createLatestRequest();
  const [attempt, setAttempt] = useState(0);
  const [result, setResult] = useState({ key: null, attempt: -1, data: null, error: null, loading: false });
  const reload = useCallback(() => setAttempt((value) => value + 1), []);

  useEffect(() => {
    const gate = request.current;
    if (key == null) return () => gate.invalidate();
    setResult({ key, attempt, data: null, error: null, loading: true });
    void gate.run(
      load,
      (data) => setResult({ key, attempt, data, error: null, loading: false }),
      (error) => setResult({ key, attempt, data: null, error, loading: false }),
    );
    return () => gate.invalidate();
    // The key, supplied by the caller, includes every input to the request.
  }, [key, attempt]);

  if (key == null) return { data: null, error: null, loading: false, reload };
  if (result.key !== key || result.attempt !== attempt) return { data: null, error: null, loading: true, reload };
  return { data: result.data, error: result.error, loading: result.loading, reload };
}
