import React, { useLayoutEffect } from 'react';
import { Check } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import { PublicShell } from '../layout/PublicShell';
import { Button, Card, cx } from '../ui';

const steps = ['Dịch vụ', 'Chuyên viên', 'Thời gian', 'Thông tin', 'Xác nhận'];

export function BookingLayout({ step, title, description, children, aside }) {
  const { pathname } = useLocation();
  useLayoutEffect(() => { window.scrollTo(0, 0); }, [pathname]);
  return <PublicShell>
    <div className="bb-booking mx-auto max-w-6xl">
      <nav aria-label="Tiến trình đặt lịch" className="bb-booking-progress mb-7">
        <ol className="bb-booking-progress__list">
          {steps.map((label, index) => {
            const number = index + 1;
            const done = number < step;
            const current = number === step;
            return <li key={label} className={cx('bb-booking-progress__step', current && 'bb-booking-progress__step--current')} aria-current={current ? 'step' : undefined}>
              <span className={cx('bb-booking-progress__number', done && 'bb-booking-progress__number--done', current && 'bb-booking-progress__number--current')}>
                {done ? <Check size={14} aria-label="Đã hoàn thành" /> : number}
              </span>
              <span className="bb-booking-progress__label">{label}</span>
            </li>;
          })}
        </ol>
      </nav>
      <header className="bb-booking-heading">
        <p className="text-xs font-bold uppercase tracking-[0.16em] text-[var(--bb-brand-strong)]">Bước {step} / 5</p>
        <h1 className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">{title}</h1>
        {description && <p className="mt-2 max-w-2xl text-sm leading-6 text-[var(--bb-muted)]">{description}</p>}
      </header>
      <div className={cx('bb-booking-content mt-7 grid gap-6', aside && 'lg:grid-cols-[minmax(0,1fr)_320px]')}>
        <Card className="bb-booking-panel min-w-0 p-4 sm:p-6">{children}</Card>
        {aside && <aside className="bb-booking-aside min-w-0">{aside}</aside>}
      </div>
    </div>
  </PublicShell>;
}

export function SelectionCard({ selected, title, meta, trailing, icon, onClick, disabled }) {
  return <button type="button" disabled={disabled} aria-pressed={selected} onClick={onClick} className={cx('bb-booking-selection min-h-20 w-full rounded-xl border p-4 text-left transition-colors disabled:opacity-50', selected ? 'border-[var(--bb-brand)] bg-[var(--bb-brand-soft)]' : 'border-[var(--bb-border)] bg-white hover:border-zinc-400 hover:bg-zinc-50')}>
    {icon && <span className="bb-booking-selection__icon grid h-10 w-10 place-items-center rounded-lg bg-white text-[var(--bb-brand-strong)] shadow-sm">{icon}</span>}
    <span className="bb-booking-selection__body">
      <span className="bb-booking-selection__title font-bold text-[var(--bb-ink)]">{title}</span>
      {meta && <span className="bb-booking-selection__meta mt-1 text-xs leading-5 text-[var(--bb-muted)]">{meta}</span>}
      {trailing && <span className="bb-booking-selection__trailing text-sm font-bold text-[var(--bb-brand-strong)]">{trailing}</span>}
    </span>
  </button>;
}

export function BookingActions({ back, onNext, nextLabel = 'Tiếp tục', disabled = false, loading = false, type = 'button', form, summary }) {
  const buttonProps = { type, form, disabled, loading, onClick: onNext };
  return <>
    <div className="bb-booking-actions mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-[var(--bb-border)] pt-5">
      {back || <span />}
      <Button {...buttonProps}>{nextLabel}</Button>
    </div>
    <div className="bb-booking-mobile-action" role="group" aria-label="Thao tác đặt lịch">
      <div className="bb-booking-mobile-action__inner">
        {summary && <span className="bb-booking-mobile-action__summary">{summary}</span>}
        {back && <span className="bb-booking-mobile-action__back">{back}</span>}
        <Button {...buttonProps} className="bb-booking-mobile-action__next">{nextLabel}</Button>
      </div>
    </div>
  </>;
}
