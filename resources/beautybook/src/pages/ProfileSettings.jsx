import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { BriefcaseBusiness, UserRound } from 'lucide-react';
import { usersApi } from '../api/apiClient';
import { Badge, Button, Card, ErrorState, Field, Input, Page, PageHeader, Select, Skeleton } from '../components/ui';
import { FileUpload } from '../components/media/FileUpload';

import { birthDateError, currentBirthDateDay } from '../utils/profileBirthDate';

const initialForm = { fullName: '', phone: '', address: '', gender: '', dateOfBirth: '', staffBio: '', experienceYears: '', emergencyContactName: '', emergencyContactPhone: '' };
const staffStatus = { ACTIVE: 'Hoạt động', INACTIVE: 'Tạm ngưng nhận lịch' };

export default function ProfileSettings() {
  const [form, setForm] = useState(initialForm);
  const [profile, setProfile] = useState(null);
  const [state, setState] = useState({ loading: true, error: '' });
  const [saving, setSaving] = useState(false);
  const [dobError, setDobError] = useState('');
  const load = async () => {
    setState({ loading: true, error: '' });
    try {
      const user = await usersApi.getMe();
      setProfile(user);
      setForm({ fullName: user.fullName || '', phone: user.phone || '', address: user.address || '', gender: user.gender || '', dateOfBirth: user.dateOfBirth ? user.dateOfBirth.slice(0, 10) : '', staffBio: user.staffProfile?.bio || '', experienceYears: user.staffProfile?.experienceYears ?? '', emergencyContactName: user.staffProfile?.emergencyContactName || '', emergencyContactPhone: user.staffProfile?.emergencyContactPhone || '' });
      setState({ loading: false, error: '' });
    } catch (requestError) { setState({ loading: false, error: requestError.message }); }
  };
  useEffect(() => { load(); }, []);
  const update = (key) => (event) => setForm((value) => ({ ...value, [key]: event.target.value }));
  const save = async (event) => {
    event.preventDefault();
    if (saving) return;
    const dateError = birthDateError(form.dateOfBirth);
    setDobError(dateError);
    if (dateError) return;
    setSaving(true);
    try { await usersApi.updateMe({ ...form, gender: form.gender || undefined, dateOfBirth: form.dateOfBirth || undefined, experienceYears: form.experienceYears === '' ? undefined : Number(form.experienceYears) }); toast.success('Đã cập nhật hồ sơ'); await load(); }
    catch (requestError) { toast.error(requestError.message); }
    finally { setSaving(false); }
  };
  return <Page className="max-w-3xl">
    <PageHeader eyebrow="Tài khoản" title="Hồ sơ cá nhân" description="Thông tin nhận diện tài khoản và liên kết hồ sơ nhân sự nếu có." />
    {state.loading ? <Card className="p-6"><Skeleton rows={4} /></Card> : state.error ? <Card><ErrorState message={state.error} onRetry={load} /></Card> : <div className="space-y-5">
      {profile?.staffProfile && <Card className="p-5"><div className="flex flex-col gap-4 sm:flex-row sm:items-center"><span className="grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-700"><BriefcaseBusiness size={20} /></span><div className="min-w-0 flex-1"><h2 className="font-bold">Hồ sơ nhân viên đã liên kết</h2><p className="mt-1 text-sm text-[var(--bb-muted)]">{profile.staffProfile.position || 'Nhân viên'} · {profile.staffProfile.branch?.name || 'Chi nhánh chưa cập nhật tên'}</p><Badge className="mt-2" tone={profile.staffProfile.status === 'ACTIVE' ? 'success' : 'warning'}>{staffStatus[profile.staffProfile.status] || profile.staffProfile.status}</Badge></div><Link to="/salon/appointments" className="inline-flex min-h-11 items-center justify-center rounded-lg border border-[var(--bb-border)] px-4 text-sm font-semibold">Lịch của tôi</Link></div></Card>}
      <Card className="overflow-hidden"><div className="flex items-center gap-3 border-b border-[var(--bb-border)] p-5"><span className="grid h-11 w-11 place-items-center rounded-xl bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]"><UserRound size={20} /></span><div><h2 className="font-bold">Thông tin cơ bản</h2><p className="text-xs text-[var(--bb-muted)]">Chỉ cập nhật thông tin của chính bạn.</p></div></div><form onSubmit={save} className="grid gap-5 p-5 sm:grid-cols-2"><div className="sm:col-span-2"><FileUpload entityType="USER_AVATAR" entityId={profile.id} value={profile.avatarMedia} label="Ảnh đại diện" onUploaded={(media) => setProfile((current) => ({ ...current, avatarMedia: media, avatarMediaId: media.id }))} onRemoved={() => setProfile((current) => ({ ...current, avatarMedia: null, avatarMediaId: null }))} /></div><Field label="Họ và tên" required className="sm:col-span-2"><Input autoComplete="name" required value={form.fullName} onChange={update('fullName')} /></Field><Field label="Số điện thoại"><Input type="tel" inputMode="tel" autoComplete="tel" value={form.phone} onChange={update('phone')} /></Field><Field label="Giới tính"><Select value={form.gender} onChange={update('gender')}><option value="">Chưa chọn</option><option value="FEMALE">Nữ</option><option value="MALE">Nam</option><option value="OTHER">Khác</option></Select></Field><Field label="Ngày sinh" error={dobError}><Input type="date" max={currentBirthDateDay()} aria-invalid={Boolean(dobError)} value={form.dateOfBirth} onChange={(event) => { setDobError(birthDateError(event.target.value)); update("dateOfBirth")(event); }} /></Field><Field label="Địa chỉ"><Input autoComplete="street-address" value={form.address} onChange={update('address')} /></Field>{profile?.staffProfile && <><Field label="Giới thiệu nghề nghiệp" className="sm:col-span-2"><Input value={form.staffBio} onChange={update('staffBio')} /></Field><Field label="Số năm kinh nghiệm"><Input type="number" min="0" max="80" value={form.experienceYears} onChange={update('experienceYears')} /></Field><Field label="Người liên hệ khẩn cấp"><Input value={form.emergencyContactName} onChange={update('emergencyContactName')} /></Field><Field label="SĐT liên hệ khẩn cấp"><Input type="tel" value={form.emergencyContactPhone} onChange={update('emergencyContactPhone')} /></Field></>}<div className="flex items-end sm:justify-end sm:col-span-2"><Button type="submit" loading={saving} className="w-full sm:w-auto">Lưu thay đổi</Button></div></form></Card>
    </div>}
  </Page>;
}
