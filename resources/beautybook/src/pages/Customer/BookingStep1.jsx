import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Gift, MapPin, Scissors } from 'lucide-react';
import { branchesApi, combosApi, servicesApi } from '../../api/apiClient';
import { useBookingStore } from '../../store/bookingStore';
import { EmptyState, ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { BookingActions, BookingLayout, SelectionCard } from '../../components/customer/BookingLayout';
import { useAsyncResource } from '../../hooks/useAsyncResource';

const variantMeta = (variant) => {
  const duration = variant.maxDurationMinutes
    ? `${variant.durationMinutes}–${variant.maxDurationMinutes} phút`
    : `${variant.durationMinutes || 0} phút`;
  return `${variant.priceDisplay || 'Giá đang cập nhật'} · ${duration}`;
};

export default function BookingStep1() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const requestedBranch = params.get('branchId');
  const requestedService = params.get('serviceId');
  const requestedStaff = params.get('staffId');
  const [branchQuery, setBranchQuery] = useState('');
  const [serviceQuery, setServiceQuery] = useState('');
  const {
    branchId, serviceIds, comboId, variantSelections,
    setBranch, toggleService, setCombo, setStaff, setVariant,
  } = useBookingStore();
  const branchResource = useAsyncResource('public-branches', () => branchesApi.getAll());
  const branches = branchResource.data?.data ?? branchResource.data ?? [];
  const loading = branchResource.loading;
  const error = branchResource.error?.message || '';
  const loadBranches = branchResource.reload;
  const catalog = useAsyncResource(branchId, async () => {
    const [serviceResult, comboResult] = await Promise.all([servicesApi.getAll(branchId), combosApi.getPublic(branchId)]);
    return { services: serviceResult.data ?? serviceResult, combos: comboResult.data ?? comboResult };
  });
  const services = catalog.data?.services ?? [];
  const combos = catalog.data?.combos ?? [];
  const serviceLoading = catalog.loading;
  const appliedLink = useRef({ key: null, branch: false, service: false, staff: false });
  const linkKey = requestedBranch ? JSON.stringify([requestedBranch, requestedService, requestedStaff]) : null;
  useEffect(() => {
    if (!linkKey) { appliedLink.current = { key: null, branch: false, service: false, staff: false }; return; }
    if (appliedLink.current.key !== linkKey) appliedLink.current = { key: linkKey, branch: false, service: false, staff: false };
    const applied = appliedLink.current;
    if (!applied.branch) {
      const requestedBranchRecord = branches.find((branch) => branch.id === requestedBranch);
      if (!requestedBranchRecord) return;
      applied.branch = true;
      if (branchId !== requestedBranch) { setBranch(requestedBranch, requestedBranchRecord.branch_name || requestedBranchRecord.name || '', [requestedBranchRecord.address, requestedBranchRecord.district].filter(Boolean).join(', ')); return; }
    }
    if (branchId !== requestedBranch || !catalog.data) return;
    if (!applied.service) {
      applied.service = true;
      if (requestedService && services.some((service) => service.id === requestedService) && !serviceIds.includes(requestedService)) {
        toggleService(requestedService);
        return;
      }
    }
    // Selecting a service invalidates staff. Apply staffId only after services are present.
    if (requestedStaff && !applied.staff && serviceIds.length) {
      applied.staff = true;
      setStaff(requestedStaff);
    }
  }, [linkKey, branches, branchId, catalog.data, requestedBranch, requestedService, requestedStaff, services, serviceIds, setBranch, toggleService, setStaff]);

  const chosen = useMemo(() => services.filter((service) => serviceIds.includes(service.id)), [services, serviceIds]);
  const visibleBranches = useMemo(() => branches.filter((branch) => `${branch.branch_name || branch.name} ${branch.address || ''} ${branch.district || ''}`.toLocaleLowerCase('vi-VN').includes(branchQuery.trim().toLocaleLowerCase('vi-VN'))), [branches, branchQuery]);
  const visibleServices = useMemo(() => services.filter((service) => service.name.toLocaleLowerCase('vi-VN').includes(serviceQuery.trim().toLocaleLowerCase('vi-VN'))), [services, serviceQuery]);
  useEffect(() => {
    if (!catalog.data) return;
    if (comboId && !combos.some((combo) => combo.id === comboId)) { setCombo(null); return; }
    serviceIds.filter((id) => !services.some((service) => service.id === id)).forEach(toggleService);
  }, [catalog.data, comboId, combos, serviceIds, services, setCombo, toggleService]);
  const variantsComplete = comboId || chosen.every((service) => !service.variants?.length || service.variants.some((variant) => variant.id === variantSelections[service.id] && variant.priceType !== 'QUOTE'));
  const canContinue = Boolean(branchId && serviceIds.length && !serviceLoading && !catalog.error && chosen.length === serviceIds.length && variantsComplete);

  return <BookingLayout step={1} title="Chọn chi nhánh và dịch vụ" description="Chọn dịch vụ bạn cần. Giá và thời lượng sẽ được kiểm tra lại trước khi xác nhận.">
    {loading ? <Skeleton rows={5} /> : error && !branches.length ? <ErrorState message={error} onRetry={loadBranches} /> : <div className="space-y-7">
      <section><h2 className="mb-3 text-sm font-bold">1. Chi nhánh</h2>{branches.length > 5 && <label className="mb-3 block text-sm font-medium">Tìm chi nhánh<Input className="mt-1" type="search" value={branchQuery} onChange={(event) => setBranchQuery(event.target.value)} placeholder="Tên hoặc địa chỉ chi nhánh" /></label>}<div className="grid gap-3 md:grid-cols-2">{visibleBranches.map((branch) => { const address = [branch.address, branch.district].filter(Boolean).join(', '); return <SelectionCard key={branch.id} selected={branchId === branch.id} onClick={() => setBranch(branch.id, branch.branch_name || branch.name || '', address)} icon={<MapPin size={18} />} title={branch.branch_name || branch.name} meta={address || 'Chưa cập nhật địa chỉ'} />; })}</div>{!visibleBranches.length && <p className="mt-3 text-sm text-[var(--bb-muted)]">Không tìm thấy chi nhánh phù hợp. Hãy thử từ khóa khác.</p>}</section>
      {branchId && !serviceLoading && combos.length > 0 && <section><h2 className="mb-3 text-sm font-bold">2. Combo ưu đãi <span className="font-normal text-[var(--bb-muted)]">(chọn một)</span></h2><div className="grid gap-3 md:grid-cols-2">{combos.map((combo) => <SelectionCard key={combo.id} selected={comboId === combo.id} onClick={() => setCombo(comboId === combo.id ? null : combo)} icon={<Gift size={18} />} title={combo.name} meta={`${combo.durationMinutes || 0} phút · tiết kiệm ${Number(combo.savingAmount || 0).toLocaleString('vi-VN')}₫`} trailing={`${Number(combo.comboPrice || 0).toLocaleString('vi-VN')}₫`} />)}</div></section>}
      {catalog.error && <ErrorState message={catalog.error.message} onRetry={catalog.reload} />}
      <section><h2 className="mb-3 text-sm font-bold">{combos.length ? 'Hoặc chọn dịch vụ riêng lẻ' : '2. Dịch vụ'} <span className="font-normal text-[var(--bb-muted)]">(có thể chọn nhiều)</span></h2>
        {services.length > 6 && <label className="mb-3 block text-sm font-medium">Tìm dịch vụ<Input className="mt-1" type="search" value={serviceQuery} onChange={(event) => setServiceQuery(event.target.value)} placeholder="Tên dịch vụ" /></label>}
        {!branchId ? <EmptyState title="Hãy chọn chi nhánh" description="Dịch vụ sẽ được tải theo chi nhánh bạn chọn." /> : serviceLoading ? <Skeleton rows={4} /> : !services.length ? <EmptyState title="Chưa có dịch vụ khả dụng" description="Cơ sở hiện chưa có dịch vụ có thể đặt trực tuyến." /> : visibleServices.length ? <div className="grid gap-3 md:grid-cols-2">{visibleServices.map((service) => <SelectionCard key={service.id} selected={!comboId && serviceIds.includes(service.id)} onClick={() => toggleService(service.id)} icon={<Scissors size={18} />} title={service.name} meta={service.durationMinutes ? `${service.durationMinutes} phút` : 'Thời lượng đang cập nhật'} trailing={service.priceDisplay || (service.price != null ? `${Number(service.price).toLocaleString('vi-VN')}₫` : 'Giá đang cập nhật')} />)}</div> : <p className="text-sm text-[var(--bb-muted)]">Không tìm thấy dịch vụ phù hợp. Hãy thử từ khóa khác.</p>}
      </section>
      {!comboId && chosen.filter((service) => service.variants?.length).map((service) => <section key={service.id} className="rounded-2xl border border-[var(--bb-border)] p-4"><h3 className="font-bold">Lựa chọn cho {service.name}</h3><p className="mt-1 text-xs text-[var(--bb-muted)]">Giá và thời lượng của lựa chọn này sẽ được giữ trong lịch hẹn.</p><Select className="mt-3" value={variantSelections[service.id] || ''} onChange={(event) => setVariant(service.id, event.target.value)}><option value="">Chọn biến thể</option>{service.variants.map((variant) => <option key={variant.id} value={variant.id} disabled={variant.priceType === 'QUOTE'}>{variant.name} · {variantMeta(variant)}</option>)}</Select>{service.variants.some((variant) => variant.priceType === 'QUOTE') && <p className="mt-3 text-xs text-amber-700">Một số lựa chọn cần báo giá trước; vui lòng liên hệ cơ sở nếu không thể đặt trực tuyến.</p>}</section>)}
      <p role="status" className="text-sm text-[var(--bb-muted)]">{!branchId ? 'Chọn chi nhánh để xem dịch vụ.' : !serviceIds.length ? 'Chọn ít nhất một dịch vụ để tiếp tục.' : !variantsComplete ? 'Chọn biến thể cho mỗi dịch vụ đã chọn.' : `Đã chọn ${serviceIds.length} dịch vụ.`}</p>
      <BookingActions disabled={!canContinue} nextLabel={variantsComplete ? 'Chọn chuyên viên' : 'Chọn đủ biến thể'} onNext={() => navigate('/book/staff')} />
    </div>}
  </BookingLayout>;
}
