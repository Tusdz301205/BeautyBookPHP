import { createContext, useContext, useEffect, useState } from 'react';
import { bookingOperationalLabels } from '../../../utils/operationalTiming';

const ClockContext = createContext(null);
export function OperationalClock({ children }) {
  const [elapsed, setElapsed] = useState(() => performance.now());
  useEffect(() => {
    const timer = setInterval(() => setElapsed(performance.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  return <ClockContext.Provider value={elapsed}>{children}</ClockContext.Provider>;
}

export function useOperationalElapsed() { return useContext(ClockContext) ?? performance.now(); }

export default function OperationalTiming({ booking }) {
  const labels = bookingOperationalLabels(booking, useOperationalElapsed());
  return labels.length ? <span className="block whitespace-normal break-words text-xs font-semibold text-amber-800">{labels.join(' · ')}</span> : null;
}
