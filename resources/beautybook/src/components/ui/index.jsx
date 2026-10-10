import { normalizeNameSearch } from '../../utils/nameSearch';
import React, { useEffect, useId, useImperativeHandle, useMemo, useRef, useState } from 'react';
import { AlertCircle, Check, CheckCircle2, ChevronDown, Inbox, LoaderCircle, Search, X } from 'lucide-react';
import { STATUS_LABELS } from '../../utils/displayLabels';

export const cx = (...classes) => classes.filter(Boolean).join(' ');

const buttonVariants = {
  primary: 'border-transparent bg-[var(--bb-brand)] text-white hover:bg-[var(--bb-brand-strong)]',
  secondary: 'border-[var(--bb-border)] bg-white text-[var(--bb-ink)] hover:bg-[var(--bb-surface-subtle)]',
  ghost: 'border-transparent bg-transparent text-[var(--bb-ink-soft)] hover:bg-[var(--bb-surface-subtle)] hover:text-[var(--bb-ink)]',
  danger: 'border-transparent bg-[var(--bb-danger)] text-white hover:brightness-90',
};

export const Button = React.forwardRef(({ variant = 'primary', size = 'md', loading = false, className = '', children, disabled, type = 'button', onClick, ...props }, ref) => {
  const sizes = { sm: 'min-h-11 px-3 text-xs sm:min-h-9', md: 'min-h-11 px-4 text-sm', lg: 'min-h-12 px-5 text-base', icon: 'h-11 w-11 p-0' };
  return <button ref={ref} type={type} disabled={disabled || loading} className={cx('bb-button inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-[var(--bb-radius-control)] border font-semibold active:brightness-95 disabled:cursor-not-allowed disabled:opacity-50', buttonVariants[variant], sizes[size], className)} {...props} onClick={(event) => { event.currentTarget.focus(); onClick?.(event); }}>{loading && <LoaderCircle size={16} className="animate-spin motion-reduce:animate-none" aria-hidden="true" />}{children}</button>;
});
Button.displayName = 'Button';

export const IconButton = React.forwardRef(({ label, children, ...props }, ref) => <Button ref={ref} size="icon" variant="ghost" aria-label={label} {...props}>{children}</Button>);
IconButton.displayName = 'IconButton';

export function Field({ label, required, hint, error, children, className = '' }) {
  const id = useId();
  const control = React.isValidElement(children) ? React.cloneElement(children, {
    id: children.props.id || id,
    'aria-required': required || undefined,
    'aria-invalid': Boolean(error),
    'aria-describedby': error ? `${id}-error` : hint ? `${id}-hint` : undefined,
  }) : children;
  return <label htmlFor={control?.props?.id || id} className={cx('bb-field grid gap-1.5 text-sm font-medium text-[var(--bb-ink)]', className)}><span>{label}{required && <span className="ml-1 text-[var(--bb-danger)]" aria-hidden="true">*</span>}{required && <span className="bb-sr-only"> (bắt buộc)</span>}</span>{control}{hint && !error && <span id={`${id}-hint`} className="min-h-[1lh] text-xs font-normal text-[var(--bb-muted)]">{hint}</span>}{error && <span id={`${id}-error`} role="alert" className="min-h-[1lh] text-xs font-normal text-[var(--bb-danger)]">{error}</span>}{!hint && !error && <span aria-hidden="true" className="min-h-[1lh] text-xs">&nbsp;</span>}</label>;
}

const controlClass = 'bb-control min-h-11 w-full rounded-[var(--bb-radius-control)] border border-[var(--bb-border)] bg-white px-3 text-sm text-[var(--bb-ink)] outline-2 outline-transparent placeholder:text-zinc-400 focus-visible:border-[var(--bb-focus)] focus-visible:outline-[var(--bb-focus)] focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:bg-[var(--bb-surface-subtle)] disabled:text-[var(--bb-muted)]';
export const Input = React.forwardRef(({ className = '', ...props }, ref) => <input ref={ref} className={cx(controlClass, className)} {...props} />);
Input.displayName = 'Input';
function readOptionLabel(children) {
  if (children === null || children === undefined || typeof children === 'boolean') return '';
  if (typeof children === 'string' || typeof children === 'number') return String(children);
  if (Array.isArray(children)) return children.map(readOptionLabel).join('');
  if (React.isValidElement(children)) return readOptionLabel(children.props.children);
  return '';
}

function collectOptions(children, result = []) {
  React.Children.forEach(children, (child) => {
    if (!React.isValidElement(child)) return;
    if (child.type === 'option') {
      const label = readOptionLabel(child.props.children);
      result.push({ value: String(child.props.value ?? label), label, disabled: Boolean(child.props.disabled) });
      return;
    }
    if (child.type === React.Fragment || child.type === 'optgroup') collectOptions(child.props.children, result);
  });
  return result;
}

export const Select = React.forwardRef(({
  className = '', children, value, defaultValue = '', onChange, onBlur,
  disabled = false, loading = false, searchable, searchPlaceholder = 'Tìm trong danh sách',
  emptyMessage = 'Không có lựa chọn phù hợp', placeholder, icon, id, name, required = false, form,
  'aria-label': ariaLabel, 'aria-describedby': ariaDescribedBy, 'aria-invalid': ariaInvalid, ...props
}, forwardedRef) => {
  const generatedId = useId();
  const selectId = id || `bb-select-${generatedId.replace(/:/g, '')}`;
  const listboxId = `${selectId}-listbox`;
  const triggerRef = useRef(null);
  const panelRef = useRef(null);
  const searchRef = useRef(null);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [activeIndex, setActiveIndex] = useState(0);
  const [internalValue, setInternalValue] = useState(() => String(defaultValue ?? ''));
  const options = useMemo(() => collectOptions(children), [children]);
  const controlled = value !== undefined;
  const selectedValue = String(controlled ? value ?? '' : internalValue);
  const selected = options.find((option) => option.value === selectedValue);
  const showSearch = searchable ?? options.length >= 8;
  const visibleOptions = useMemo(() => {
    const normalizedQuery = normalizeNameSearch(query);
    if (!normalizedQuery) return options;
    return options.filter((option) => normalizeNameSearch(option.label).includes(normalizedQuery));
  }, [options, query]);

  useImperativeHandle(forwardedRef, () => triggerRef.current);

  const close = (restoreFocus = false) => {
    setOpen(false);
    setQuery('');
    onBlur?.({ target: { value: selectedValue, name } });
    if (restoreFocus) window.requestAnimationFrame(() => triggerRef.current?.focus());
  };

  const openList = () => {
    if (disabled || loading) return;
    setOpen(true);
    setQuery('');
    setActiveIndex(Math.max(0, options.findIndex((option) => option.value === selectedValue)));
    if (showSearch) window.requestAnimationFrame(() => searchRef.current?.focus());
  };

  const choose = (option) => {
    if (!option || option.disabled) return;
    if (!controlled) setInternalValue(option.value);
    if (option.value !== selectedValue) {
      onChange?.({ target: { value: option.value, name }, currentTarget: { value: option.value, name } });
    }
    close(true);
  };

  useEffect(() => {
    if (!open) return undefined;
    const onPointerDown = (event) => {
      const path = event.composedPath();
      if (!path.includes(panelRef.current) && !path.includes(triggerRef.current)) close(false);
    };
    const onKeyDown = (event) => {
      if (event.key === 'Escape') { event.preventDefault(); close(true); return; }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const direction = event.key === 'ArrowDown' ? 1 : -1;
        setActiveIndex((current) => {
          if (!visibleOptions.length) return 0;
          let next = current;
          do next = (next + direction + visibleOptions.length) % visibleOptions.length;
          while (visibleOptions[next]?.disabled && next !== current);
          return next;
        });
        return;
      }
      if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        setActiveIndex(event.key === 'Home' ? 0 : Math.max(0, visibleOptions.length - 1));
        return;
      }
      if (event.key === 'Enter' && visibleOptions[activeIndex]) { event.preventDefault(); choose(visibleOptions[activeIndex]); return; }
      if (event.key === 'Tab' && window.matchMedia('(max-width: 39.99rem)').matches) {
        const focusable = [...(panelRef.current?.querySelectorAll('input:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])') || [])]
          .filter((element) => element.getClientRects().length);
        if (!focusable.length) return;
        const first = focusable[0]; const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    };
    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => { document.removeEventListener('pointerdown', onPointerDown); document.removeEventListener('keydown', onKeyDown); };
  }, [activeIndex, open, selectedValue, visibleOptions]);

  useEffect(() => {
    if (activeIndex >= visibleOptions.length) setActiveIndex(Math.max(0, visibleOptions.length - 1));
  }, [activeIndex, visibleOptions.length]);

  return <span className={cx('bb-select', open && 'bb-select--open', disabled && 'bb-select--disabled', className)}>
    <button
      {...props}
      ref={triggerRef}
      id={selectId}
      type="button"
      role="combobox"
      className={cx(controlClass, 'bb-select__trigger')}
      aria-label={ariaLabel}
      aria-describedby={ariaDescribedBy}
      aria-invalid={ariaInvalid}
      aria-required={required || undefined}
      aria-expanded={open}
      aria-haspopup="listbox"
      aria-controls={listboxId}
      aria-activedescendant={open && visibleOptions[activeIndex] ? `${listboxId}-option-${activeIndex}` : undefined}
      disabled={disabled || loading}
      onClick={() => open ? close(false) : openList()}
      onKeyDown={(event) => {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key) && !open) { event.preventDefault(); openList(); }
      }}
    >
      {icon ? <span className="bb-select__leading" aria-hidden="true">{icon}</span> : null}
      <span className={cx('bb-select__value', !selected && 'bb-select__placeholder')}>{loading ? 'Đang tải…' : selected?.label || placeholder || 'Chọn một giá trị'}</span>
      {loading ? <LoaderCircle className="bb-select__chevron animate-spin motion-reduce:animate-none" size={17} aria-hidden="true" /> : <ChevronDown className="bb-select__chevron" size={17} aria-hidden="true" />}
    </button>
    {name ? <input type="hidden" name={name} form={form} value={selectedValue} disabled={disabled} /> : null}
    {required ? <input className="bb-select__validation" tabIndex="-1" aria-hidden="true" value={selectedValue} readOnly required disabled={disabled} form={form} onInvalid={(event) => { event.preventDefault(); triggerRef.current?.focus(); }} /> : null}
    {open ? <>
      <span className="bb-select__backdrop" aria-hidden="true" onPointerDown={() => close(true)} />
      <span ref={panelRef} className="bb-select__panel">
        <span className="bb-select__mobile-heading"><strong>{ariaLabel || placeholder || 'Chọn một giá trị'}</strong><button type="button" onClick={() => close(true)} aria-label="Đóng danh sách"><X size={18} /></button></span>
        {showSearch ? <span className="bb-select__search"><Search size={16} aria-hidden="true" /><input ref={searchRef} value={query} onChange={(event) => { setQuery(event.target.value); setActiveIndex(0); }} placeholder={searchPlaceholder} aria-label={searchPlaceholder} autoComplete="off" /></span> : null}
        <span id={listboxId} role="listbox" aria-label={ariaLabel || placeholder || 'Danh sách lựa chọn'} className="bb-select__list bb-scrollbar">
          {visibleOptions.length ? visibleOptions.map((option, index) => <button type="button" role="option" id={`${listboxId}-option-${index}`} aria-selected={option.value === selectedValue} disabled={option.disabled} tabIndex="-1" key={`${option.value}-${index}`} className={cx('bb-select__option', index === activeIndex && 'bb-select__option--active')} onPointerMove={() => setActiveIndex(index)} onClick={() => choose(option)}><span>{option.label}</span>{option.value === selectedValue ? <Check size={17} aria-hidden="true" /> : null}</button>) : <span className="bb-select__empty">{emptyMessage}</span>}
        </span>
      </span>
    </> : null}
  </span>;
});
Select.displayName = 'Select';
export const Textarea = React.forwardRef(({ className = '', ...props }, ref) => <textarea ref={ref} className={cx(controlClass, 'min-h-28 py-3', className)} {...props} />);
Textarea.displayName = 'Textarea';

export function Card({ as: Component = 'section', className = '', children, ...props }) {
  return <Component className={cx('bb-card rounded-[var(--bb-radius-card)] border border-[var(--bb-border)] bg-[var(--bb-surface)]', className)} {...props}>{children}</Component>;
}

export function Page({ children, className = '' }) { return <div className={cx('bb-page mx-auto w-full max-w-[1600px] space-y-6', className)}>{children}</div>; }
export function PageHeader({ eyebrow, title, description, actions, children }) { return <header className="bb-page-header flex flex-col gap-4 md:flex-row md:items-end md:justify-between"><div className="min-w-0">{eyebrow && <p className="bb-page-header__eyebrow mb-1 text-xs font-bold uppercase tracking-[0.16em] text-[var(--bb-brand-strong)]">{eyebrow}</p>}<h1 className="min-w-0 [overflow-wrap:anywhere] text-2xl font-bold tracking-tight text-[var(--bb-ink)] sm:text-3xl">{title}</h1>{description && <p className="mt-1.5 max-w-3xl text-sm leading-6 text-[var(--bb-muted)]">{description}</p>}{children}</div>{actions && <div className="bb-page-header__actions flex flex-wrap items-center gap-2 md:shrink-0">{actions}</div>}</header>; }
export function SectionHeader({ title, description, actions }) { return <div className="bb-section-header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h2 className="text-base font-bold text-[var(--bb-ink)]">{title}</h2>{description && <p className="mt-1 text-sm text-[var(--bb-muted)]">{description}</p>}</div>{actions}</div>; }

export function MetricCard({ icon: Icon, label, value = '—', note, tone = 'brand' }) {
  const tones = { brand: 'bg-pink-50 text-pink-700', success: 'bg-emerald-50 text-emerald-700', warning: 'bg-amber-50 text-amber-700', info: 'bg-blue-50 text-blue-700', neutral: 'bg-zinc-100 text-zinc-700' };
  return <Card className="bb-metric-card p-4"><div className={cx('grid h-9 w-9 place-items-center rounded-lg', tones[tone] || tones.neutral)}>{Icon && <Icon size={17} aria-hidden="true" />}</div><p className="mt-3 text-2xl font-bold tabular-nums text-[var(--bb-ink)]">{typeof value === 'number' ? value.toLocaleString('vi-VN') : value}</p><p className="mt-0.5 text-xs font-semibold text-[var(--bb-muted)]">{label}</p>{note && <p className="mt-2 text-xs text-[var(--bb-ink-soft)]">{note}</p>}</Card>;
}

export function Badge({ tone = 'neutral', children, className = '' }) {
  const tones = { neutral: 'border-zinc-200 bg-zinc-50 text-zinc-700', brand: 'border-pink-200 bg-pink-50 text-pink-700', success: 'border-emerald-200 bg-emerald-50 text-emerald-700', warning: 'border-amber-200 bg-amber-50 text-amber-800', danger: 'border-red-200 bg-red-50 text-red-700', info: 'border-blue-200 bg-blue-50 text-blue-700' };
  const content = typeof children === 'string' ? STATUS_LABELS[children] || children : children;
  return <span className={cx('bb-badge inline-flex min-h-6 items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold', tones[tone] || tones.neutral, className)}>{content}</span>;
}

export function Skeleton({ rows = 5, className = '' }) { return <div aria-label="Đang tải" aria-busy="true" className={cx('animate-pulse space-y-3 motion-reduce:animate-none', className)}>{Array.from({ length: rows }, (_, i) => <div key={i} className="h-12 rounded-lg bg-zinc-100" />)}</div>; }
export function EmptyState({ title = 'Chưa có dữ liệu', description, action, icon: Icon = Inbox }) { return <div className="grid min-h-64 place-items-center p-6 text-center"><div><span className="mx-auto grid h-12 w-12 place-items-center rounded-full bg-[var(--bb-surface-subtle)] text-[var(--bb-muted)]"><Icon size={22} /></span><h3 className="mt-3 font-bold">{title}</h3>{description && <p className="mt-1 max-w-md text-sm text-[var(--bb-muted)]">{description}</p>}{action && <div className="mt-4">{action}</div>}</div></div>; }
export function ErrorState({ title = 'Không thể tải dữ liệu', message, onRetry }) { return <div role="alert" className="grid min-h-64 place-items-center p-6 text-center"><div><AlertCircle className="mx-auto text-[var(--bb-danger)]" /><h3 className="mt-3 font-bold">{title}</h3>{message && <p className="mt-1 max-w-md text-sm text-[var(--bb-muted)]">{message}</p>}{onRetry && <Button variant="secondary" className="mt-4" onClick={onRetry}>Thử lại</Button>}</div></div>; }
export function SuccessState({ title, message, action }) { return <div className="grid min-h-64 place-items-center p-6 text-center"><div><CheckCircle2 className="mx-auto text-[var(--bb-success)]" size={34} /><h2 className="mt-3 text-xl font-bold">{title}</h2>{message && <p className="mt-1 text-sm text-[var(--bb-muted)]">{message}</p>}{action && <div className="mt-5">{action}</div>}</div></div>; }

export function Dialog({ open, onClose, title, description, children, footer, size = 'md' }) {
  const closeRef = useRef(null);
  const dialogRef = useRef(null);
  const previousFocus = useRef(null);
  const onCloseRef = useRef(onClose);
  const titleId = useId();
  const descriptionId = useId();
  const closeDialog = () => {
    let target = previousFocus.current;
    if (!target || target === document.body || dialogRef.current?.contains(target)) {
      target = [...document.querySelectorAll('button')].find((button) => !dialogRef.current?.contains(button) && button.textContent?.trim() === title);
    }
    onCloseRef.current?.();
    requestAnimationFrame(() => target?.focus?.());
  };
  useEffect(() => { onCloseRef.current = onClose; }, [onClose]);
  useEffect(() => {
    if (open) return undefined;
    const rememberTrigger = (event) => {
      if (event.target instanceof HTMLElement) previousFocus.current = event.target;
    };
    document.addEventListener('pointerdown', rememberTrigger, true);
    return () => document.removeEventListener('pointerdown', rememberTrigger, true);
  }, [open]);
  useEffect(() => {
    if (!open) return undefined;
    if (document.activeElement instanceof HTMLElement && document.activeElement !== document.body) previousFocus.current = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => (dialogRef.current?.querySelector('[autofocus]') || closeRef.current)?.focus());
    const key = (event) => {
      if (event.key === 'Escape') { event.preventDefault(); closeDialog(); return; }
      if (event.key !== 'Tab') return;
      const focusable = [...(dialogRef.current?.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])') || [])].filter((element) => !element.hidden && element.getClientRects().length);
      if (!focusable.length) { event.preventDefault(); return; }
      const first = focusable[0]; const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', key);
    return () => { document.removeEventListener('keydown', key); document.body.style.overflow = previousOverflow; };
  }, [open, title]);
  if (!open) return null;
  const widths = { sm: 'max-w-md', md: 'max-w-xl', lg: 'max-w-3xl', xl: 'max-w-5xl' };
  return <div className="fixed inset-0 z-[var(--z-modal)] grid items-end bg-[var(--color-overlay)] p-0 sm:place-items-center sm:p-4" role="presentation"><button tabIndex="-1" className="absolute inset-0" onClick={closeDialog} aria-label="Đóng hộp thoại" /><section ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={description ? descriptionId : undefined} className={cx('relative flex max-h-[100dvh] w-full flex-col rounded-t-[var(--bb-radius-panel)] bg-white shadow-[var(--bb-shadow-float)] sm:max-h-[90dvh] sm:rounded-[var(--bb-radius-panel)]', widths[size])}><header className="flex items-start gap-4 border-b border-[var(--bb-border)] p-5"><div className="min-w-0 flex-1"><h2 id={titleId} className="text-lg font-bold">{title}</h2>{description && <p id={descriptionId} className="mt-1 text-sm text-[var(--bb-muted)]">{description}</p>}</div><IconButton ref={closeRef} label="Đóng" onClick={closeDialog}><X size={19} /></IconButton></header><div className="bb-scrollbar flex-1 overflow-y-auto p-5">{children}</div>{footer && <footer className="flex flex-wrap justify-end gap-2 border-t border-[var(--bb-border)] p-4">{footer}</footer>}</section></div>;
}

export function Drawer({ open = true, onClose, title, description, children, footer, size = 'md' }) {
  const closeRef = useRef(null);
  const drawerRef = useRef(null);
  const titleId = useId();
  const descriptionId = useId();
  useEffect(() => {
    if (!open) return undefined;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => closeRef.current?.focus());
    const onKeyDown = (event) => {
      if (event.key === 'Escape') { event.preventDefault(); onClose?.(); return; }
      if (event.key !== 'Tab') return;
      const focusable = [...(drawerRef.current?.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])') || [])].filter((element) => !element.hidden && element.getClientRects().length);
      if (!focusable.length) { event.preventDefault(); return; }
      const first = focusable[0]; const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', onKeyDown);
    return () => { document.removeEventListener('keydown', onKeyDown); document.body.style.overflow = previousOverflow; };
  }, [onClose, open]);
  if (!open) return null;
  const widths = { sm: 'max-w-md', md: 'max-w-2xl', lg: 'max-w-4xl' };
  return <div className="fixed inset-0 z-[var(--z-modal)] flex justify-end bg-[var(--color-overlay)]" role="presentation"><button tabIndex="-1" className="absolute inset-0" onClick={onClose} aria-label="Đóng ngăn chi tiết" /><aside ref={drawerRef} role="dialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={description ? descriptionId : undefined} className={cx('relative flex h-full w-full flex-col bg-white shadow-[var(--bb-shadow-float)]', widths[size])}><header className="flex items-start gap-4 border-b border-[var(--bb-border)] p-5"><div className="min-w-0 flex-1"><h2 id={titleId} className="text-lg font-bold">{title}</h2>{description && <p id={descriptionId} className="mt-1 text-sm text-[var(--bb-muted)]">{description}</p>}</div><IconButton ref={closeRef} label="Đóng" onClick={onClose}><X size={19} /></IconButton></header><div className="bb-scrollbar flex-1 overflow-y-auto p-5">{children}</div>{footer && <footer className="flex flex-wrap justify-end gap-2 border-t border-[var(--bb-border)] p-4">{footer}</footer>}</aside></div>;
}

export function ConfirmDialog({ open, onClose, onConfirm, title, description, children, confirmLabel = 'Xác nhận', cancelLabel = 'Hủy', tone = 'danger', loading = false, disabled = false }) {
  return <Dialog open={open} onClose={onClose} title={title} description={description} footer={<><Button variant="secondary" onClick={onClose}>{cancelLabel}</Button><Button variant={tone} loading={loading} disabled={disabled} onClick={onConfirm}>{confirmLabel}</Button></>}>{children}</Dialog>;
}

export function InlineNotice({ tone = 'info', children }) { const tones = { info: 'border-blue-200 bg-blue-50 text-blue-800', success: 'border-emerald-200 bg-emerald-50 text-emerald-800', warning: 'border-amber-200 bg-amber-50 text-amber-900', danger: 'border-red-200 bg-red-50 text-red-800' }; return <div role={tone === 'danger' ? 'alert' : 'status'} className={cx('rounded-lg border px-4 py-3 text-sm', tones[tone])}>{children}</div>; }
