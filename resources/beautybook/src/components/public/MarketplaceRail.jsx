import { useCallback, useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export function MarketplaceRail({ label, children, className = '' }) {
  const railRef = useRef(null);
  const [state, setState] = useState({ canBack: false, canForward: false, overflow: false });

  const measure = useCallback(() => {
    const rail = railRef.current;
    if (!rail) return;
    const max = Math.max(0, rail.scrollWidth - rail.clientWidth);
    setState({
      overflow: max > 4,
      canBack: rail.scrollLeft > 4,
      canForward: rail.scrollLeft < max - 4,
    });
  }, []);

  useEffect(() => {
    measure();
    const rail = railRef.current;
    if (!rail) return undefined;
    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(measure) : null;
    observer?.observe(rail);
    rail.addEventListener('scroll', measure, { passive: true });
    window.addEventListener('resize', measure);
    return () => {
      observer?.disconnect();
      rail.removeEventListener('scroll', measure);
      window.removeEventListener('resize', measure);
    };
  }, [children, measure]);

  const move = (direction) => {
    const rail = railRef.current;
    if (!rail) return;
    rail.scrollBy({ left: direction * Math.max(260, rail.clientWidth * 0.78), behavior: 'smooth' });
  };

  return (
    <div className={`bb-market-rail ${className}`.trim()}>
      {state.overflow ? (
        <div className="bb-market-rail__controls" aria-label={`Điều hướng ${label}`}>
          <button type="button" onClick={() => move(-1)} disabled={!state.canBack} aria-label={`Xem mục trước trong ${label}`}><ChevronLeft size={20} /></button>
          <button type="button" onClick={() => move(1)} disabled={!state.canForward} aria-label={`Xem mục tiếp theo trong ${label}`}><ChevronRight size={20} /></button>
        </div>
      ) : null}
      <div ref={railRef} className="bb-market-rail__track" role="region" aria-label={label} tabIndex="0">
        {children}
      </div>
    </div>
  );
}
