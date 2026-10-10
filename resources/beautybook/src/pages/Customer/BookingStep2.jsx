import React, { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Star, UserRound, UsersRound } from 'lucide-react';
import { staffApi } from '../../api/apiClient';
import { useBookingStore } from '../../store/bookingStore';
import { Button, EmptyState, InlineNotice, Skeleton } from '../../components/ui';
import { BookingActions, BookingLayout, SelectionCard } from '../../components/customer/BookingLayout';
import { useAsyncResource } from '../../hooks/useAsyncResource';

export default function BookingStep2() {
  const navigate = useNavigate();
  const { branchId, serviceIds, staffId, staffName, setStaff, setStaffName } = useBookingStore();
  const { data, loading, error, reload } = useAsyncResource(branchId && serviceIds.length ? JSON.stringify([branchId, serviceIds]) : null, () => staffApi.getPublic(branchId, serviceIds));
  const staff = Array.isArray(data) ? data : [];
  const warning = error ? `Không tải được danh sách chuyên viên: ${error.message}. Hãy thử tải lại hoặc chọn tự phân công.` : '';
  useEffect(() => { if (!branchId || !serviceIds.length) navigate('/book', { replace: true }); }, [branchId, serviceIds, navigate]);
  useEffect(() => { if (data && staffId && !staff.some((person) => person.id === staffId)) setStaff(null); }, [data, staffId, staff, setStaff]);
  useEffect(() => { const selected = staffId && staff.find((person) => person.id === staffId); if (selected?.fullName && staffName !== selected.fullName) setStaffName(staffId, selected.fullName); }, [staff, staffId, staffName, setStaffName]);
  return <BookingLayout step={2} title="Chọn chuyên viên" description="Chọn người bạn muốn gặp, hoặc để hệ thống tìm chuyên viên phù hợp và còn lịch trống.">
    {warning && <div className="space-y-2"><InlineNotice tone="warning">{warning}</InlineNotice><Button variant="secondary" onClick={reload}>Tải lại danh sách</Button></div>}
    <div className="mt-4 grid gap-3 md:grid-cols-2"><SelectionCard selected={staffId === null} onClick={() => setStaff(null)} icon={<UsersRound size={18} />} title="Bất kỳ chuyên viên phù hợp" meta="Hệ thống chọn người đủ kỹ năng và còn thời gian trống." trailing="Đề xuất" />{loading ? <div className="md:col-span-2"><Skeleton rows={4} /></div> : staff.map((person) => { const specialty = person.specialties?.join(' · '); const rating = person.ratingCount ? <span className="inline-flex items-center gap-1"><Star size={13} fill="currentColor" />{person.rating} ({person.ratingCount})</span> : null; return <SelectionCard key={person.id} selected={staffId === person.id} onClick={() => setStaff(person.id, person.fullName || '')} icon={person.avatarUrl ? <img className="h-10 w-10 rounded-lg object-cover" src={person.avatarUrl} alt="" width="40" height="40" /> : <UserRound size={18} />} title={person.fullName || 'Chuyên viên'} meta={<>{person.professionalTitle || 'Chuyên viên làm đẹp'}{specialty ? <span className="block">{specialty}</span> : null}</>} trailing={rating} />; })}</div>
    {!loading && !staff.length && !warning && <EmptyState title="Chưa có chuyên viên phù hợp để chọn" description="Bạn vẫn có thể tiếp tục với lựa chọn tự phân công. Lịch và kỹ năng sẽ được kiểm tra lại khi bạn xác nhận." />}
    <BookingActions back={<Button variant="secondary" onClick={() => navigate('/book')}>Quay lại</Button>} disabled={loading || Boolean(staffId && !staff.some((person) => person.id === staffId))} nextLabel="Chọn thời gian" onNext={() => navigate('/book/time')} />
  </BookingLayout>;
}
