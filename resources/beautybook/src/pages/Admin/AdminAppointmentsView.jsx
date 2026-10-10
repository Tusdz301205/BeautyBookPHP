import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertCircle, BarChart3, CalendarDays, List, MapPin, RefreshCw } from 'lucide-react';
import { branchesApi } from '../../api/apiClient';
import SchedulerListView from '../../components/admin/scheduler/SchedulerListView';
import SchedulerStats from '../../components/admin/scheduler/SchedulerStats';
import SchedulerView from '../../components/admin/scheduler/SchedulerView';
import { Select } from '../../components/ui';
import { useAuthStore } from '../../store/authStore';
import AppointmentQueues from '../../components/appointments/AppointmentQueues';

const emptyStats = {
  total: 0,
  pending: 0,
  confirmed: 0,
  checkedIn: 0,
  inProgress: 0,
  completed: 0,
  cancelled: 0,
  noShow: 0,
};

const statCards = [
  { key: 'total', label: 'Tổng lịch', tone: 'neutral' },
  { key: 'pending', label: 'Mới', tone: 'amber' },
  { key: 'confirmed', label: 'Đã xác nhận', tone: 'blue' },
  { key: 'checkedIn', label: 'Đã đến', tone: 'cyan' },
  { key: 'inProgress', label: 'Đang thực hiện', tone: 'violet' },
  { key: 'completed', label: 'Hoàn thành', tone: 'green' },
  { key: 'cancelled', label: 'Đã hủy', tone: 'red' },
  { key: 'noShow', label: 'Không đến', tone: 'zinc' },
];

const hasAnyRole = (roleCodes, roles) => roles.some((role) => roleCodes.has(role));

export function AppointmentCalendarWorkspace({ zone = 'admin', headerAction = null, externalRefreshKey = 0 }) {
  const user = useAuthStore((state) => state.user);
  const can = useAuthStore((state) => state.can);
  const roleCodes = useMemo(() => new Set([
    ...(user?.roles || []),
    ...(user?.scopes || []).map((scope) => scope.code),
  ]), [user]);
  const staffOnly = roleCodes.has('STAFF') && !hasAnyRole(roleCodes, ['PLATFORM_ADMIN', 'BUSINESS_OWNER', 'RECEPTIONIST']);
  const owner = roleCodes.has('BUSINESS_OWNER');
  const dayFirst = staffOnly || roleCodes.has('RECEPTIONIST') || (typeof window !== 'undefined' && window.innerWidth < 640);

  const [branches, setBranches] = useState([]);
  const [selectedBusiness, setSelectedBusiness] = useState('__all__');
  const [selectedBranch, setSelectedBranch] = useState('');
  const [activeTab, setActiveTab] = useState('calendar');
  const [stats, setStats] = useState(emptyStats);
  const [statsPeriod, setStatsPeriod] = useState('');
  const [calendarRefreshKey, setCalendarRefreshKey] = useState(0);
  const [branchesLoading, setBranchesLoading] = useState(true);
  const [branchesError, setBranchesError] = useState('');
  const [selectedDate, setSelectedDate] = useState(new Date());
  const [viewMode, setViewMode] = useState(dayFirst ? 'day' : 'week');

  const isPlatform = can('branch:read:platform');

  const fetchBranches = useCallback(async () => {
    setBranchesLoading(true);
    setBranchesError('');
    try {
      const data = isPlatform ? await branchesApi.getManage() : await branchesApi.getAccessible();
      const list = Array.isArray(data) ? data : data?.data || data?.items || [];
      setBranches(list);
      setSelectedBranch((current) => {
        if (current === '__all__' && list.length > 1) return current;
        if (list.some((branch) => branch.id === current)) return current;
        return owner && list.length > 1 ? '__all__' : list[0]?.id || '';
      });
    } catch (error) {
      setBranchesError(error.message || 'Không thể tải danh sách chi nhánh.');
      setBranches([]);
      setSelectedBranch('');
    } finally {
      setBranchesLoading(false);
    }
  }, [isPlatform, owner, user]);

  useEffect(() => { fetchBranches(); }, [fetchBranches]);

  const businesses = useMemo(() => {
    const grouped = new Map();
    branches.forEach((branch) => {
      if (!branch.businessId) return;
      const name = branch.business?.name || (branch.branch_name ? branch.name : null) || `Doanh nghiệp ${branch.businessId.slice(0, 8)}`;
      if (!grouped.has(branch.businessId)) grouped.set(branch.businessId, name);
    });
    return [...grouped].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name, 'vi'));
  }, [branches]);

  const visibleBranches = useMemo(
    () => selectedBusiness === '__all__' ? branches : branches.filter((branch) => branch.businessId === selectedBusiness),
    [branches, selectedBusiness],
  );

  useEffect(() => {
    if (branchesLoading) return;
    setSelectedBranch((current) => {
      if (current === '__all__') return visibleBranches.length > 1 ? current : visibleBranches[0]?.id || '';
      return visibleBranches.some((branch) => branch.id === current)
        ? current
        : (owner || isPlatform) && visibleBranches.length > 1 ? '__all__' : visibleBranches[0]?.id || '';
    });
  }, [branchesLoading, isPlatform, owner, visibleBranches]);

  const activeBranchIds = useMemo(
    () => selectedBranch === '__all__' ? visibleBranches.map((branch) => branch.id) : selectedBranch ? [selectedBranch] : [],
    [selectedBranch, visibleBranches],
  );
  const tabs = useMemo(() => [
    { id: 'calendar', label: 'Lịch hẹn', icon: CalendarDays },
    ...(zone === 'salon' && !staffOnly && (can('change_request:approve:branch') || can('change_request:approve:tenant'))
      ? [{ id: 'queue', label: 'Việc cần xử lý', icon: List }] : []),
    ...(!staffOnly ? [
      { id: 'list', label: 'Danh sách', icon: List },
      { id: 'stats', label: 'Thống kê', icon: BarChart3 },
    ] : []),
  ], [can, staffOnly, user, zone]);

  const handleStatsChange = useCallback((nextStats, period) => {
    setStats(nextStats);
    setStatsPeriod(period);
  }, []);

  const branchLabel = (branch) => {
    const businessName = branch.branch_name ? branch.name : '';
    const name = branch.branch_name || branch.name || 'Chi nhánh';
    return businessName && businessName !== name ? `${businessName} — ${name}` : name;
  };

  const multiBranchSelection = activeBranchIds.length > 1;
  const receptionist = roleCodes.has('RECEPTIONIST') && !staffOnly;
  const title = zone === 'salon'
    ? staffOnly ? 'Lịch của tôi' : owner ? 'Lịch hẹn toàn doanh nghiệp'  : receptionist ? 'Lịch hẹn tại quầy' : 'Lịch hẹn vận hành'
    : 'Quản lý lịch hẹn';
  const description = zone === 'salon'
    ? staffOnly
      ? 'Chỉ hiển thị các lịch đã được phân công cho bạn; bộ lọc nhân viên được khóa theo hồ sơ hiện tại.'
      : receptionist
      ? 'Tiếp nhận khách, xác nhận lịch hẹn và theo dõi hoạt động tại chi nhánh được cấp.'
        : owner
          ? 'Theo dõi lịch hẹn trên toàn doanh nghiệp hoặc lọc theo từng chi nhánh.'
          : 'Theo dõi lịch hẹn, trạng thái và phân công nhân viên trong phạm vi chi nhánh được cấp.'
    : 'Theo dõi công suất, trạng thái và phân công nhân viên theo thời gian thực.';

  return (
    <div className="flex min-h-[calc(100vh-5rem)] flex-col bg-[#f7f6f3]">
      <header className="shrink-0 border-b border-zinc-200 bg-white px-4 pt-5 sm:px-6 lg:px-8">
        <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-pink-700">{staffOnly ? 'Cá nhân' : receptionist ? 'Quầy lễ tân' : owner ? 'Toàn doanh nghiệp' : 'Vận hành'}</p>
            <h1 className="mt-1 text-2xl font-bold tracking-tight text-zinc-950">{title}</h1>
            <p className="mt-1 text-sm text-zinc-500">{description}</p>
          </div>

          <div className="grid w-full gap-2 sm:grid-cols-2 xl:flex xl:w-auto">
            {headerAction}
            {isPlatform && businesses.length > 1 && (
              <div className="relative min-w-0 xl:w-64">
                <span className="sr-only">Doanh nghiệp</span>
                <Select aria-label="Doanh nghiệp" value={selectedBusiness} onChange={(event) => setSelectedBusiness(event.target.value)} searchable searchPlaceholder="Tìm doanh nghiệp">
                  <option value="__all__">Tất cả doanh nghiệp</option>
                  {businesses.map((business) => <option key={business.id} value={business.id}>{business.name}</option>)}
                </Select>
              </div>
            )}
            <div className="flex min-w-0 gap-2">
              <div className="relative min-w-0 flex-1 xl:w-72 xl:flex-none">
                <span className="sr-only">Chi nhánh</span>
                <Select className="bb-scheduler-branch-select" aria-label="Chi nhánh" icon={<MapPin size={16} />} value={selectedBranch} onChange={(event) => setSelectedBranch(event.target.value)} loading={branchesLoading} disabled={branchesLoading || visibleBranches.length === 0} searchable searchPlaceholder="Tìm chi nhánh">
                  {branchesLoading && <option value="">Đang tải chi nhánh...</option>}
                  {!branchesLoading && visibleBranches.length === 0 && <option value="">Không có chi nhánh</option>}
                  {visibleBranches.length > 1 && (owner || isPlatform) && <option value="__all__">Tất cả chi nhánh ({visibleBranches.length})</option>}
                  {visibleBranches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}
                </Select>
              </div>
              <button type="button" onClick={fetchBranches} className="grid h-11 w-11 shrink-0 place-items-center rounded-xl border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50" aria-label="Tải lại chi nhánh" title="Tải lại chi nhánh">
                <RefreshCw size={17} className={branchesLoading ? 'animate-spin' : ''} />
              </button>
            </div>
          </div>
        </div>

        {branchesError && (
          <div role="alert" className="mt-4 flex items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <span className="flex items-center gap-2"><AlertCircle size={17} />{branchesError}</span>
            <button type="button" onClick={fetchBranches} className="font-bold underline">Thử lại</button>
          </div>
        )}

        {activeTab === 'calendar' && <section className="mt-5" aria-label={`Thống kê lịch hẹn ${statsPeriod}`}>
          <div className="mb-2 flex items-center justify-between gap-3">
            <h2 className="text-xs font-bold uppercase tracking-wide text-zinc-500">Phạm vi đang xem</h2>
            <span className="text-xs font-medium text-zinc-500">{statsPeriod}</span>
          </div>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-8">
            {statCards.map((card) => <StatCard key={card.key} label={card.label} value={stats[card.key]} tone={card.tone} onClick={() => !multiBranchSelection && !staffOnly && setActiveTab('list')} />)}
          </div>
        </section>}

        <nav className="mt-5 flex flex-wrap gap-1 sm:flex-nowrap sm:overflow-x-auto" aria-label="Các phần lịch hẹn">
          {tabs.map(({ id, label, icon: Icon }) => (
            <button key={id} type="button" onClick={() => setActiveTab(id)} aria-current={activeTab === id ? 'page' : undefined} className={`flex min-h-12 shrink-0 items-center gap-2 border-b-2 px-3 text-sm font-semibold transition sm:px-4 ${activeTab === id ? 'border-pink-600 text-pink-700' : 'border-transparent text-zinc-500 hover:text-zinc-900'}`}>
              <Icon size={17} /> {label}
            </button>
          ))}
        </nav>
      </header>

      <section className="min-h-[32rem] flex-1 overflow-hidden">
        {!branchesLoading && !selectedBranch && !branchesError && (
          <div className="grid h-full place-items-center px-6 text-center"><div><MapPin className="mx-auto text-zinc-400" /><p className="mt-3 font-semibold text-zinc-900">Chưa có chi nhánh trong phạm vi quyền của bạn</p></div></div>
        )}
        {activeTab === 'calendar' && activeBranchIds.length > 0 && <SchedulerView branchId={multiBranchSelection ? undefined : activeBranchIds[0]} branchIds={activeBranchIds} branches={visibleBranches} refreshKey={`${calendarRefreshKey}-${externalRefreshKey}`} viewMode={viewMode} setViewMode={setViewMode} selectedDate={selectedDate} setSelectedDate={setSelectedDate} onStatsChange={handleStatsChange} />}
        {activeTab === 'queue' && tabs.some((tab) => tab.id === 'queue') && activeBranchIds.length > 0 && <AppointmentQueues branchIds={activeBranchIds} branches={visibleBranches} onChanged={() => setCalendarRefreshKey((value) => value + 1)} />}
        {activeTab === 'list' && selectedBranch && <SchedulerListView zone={zone} branchId={multiBranchSelection ? undefined : selectedBranch} branchIds={activeBranchIds} />}
        {activeTab === 'stats' && selectedBranch && <SchedulerStats branchId={multiBranchSelection ? undefined : selectedBranch} branchIds={activeBranchIds} />}
      </section>
    </div>
  );
}

export function AdminAppointmentsView() {
  return <AppointmentCalendarWorkspace />;
}

function SingleBranchNotice() {
      return <div className="grid h-full place-items-center px-6 text-center"><div><MapPin className="mx-auto text-zinc-400" /><p className="mt-3 font-semibold text-zinc-900">Chọn một chi nhánh để xem phần này</p><p className="mt-1 text-sm text-zinc-500">Lịch vẫn hỗ trợ xem gộp nhiều chi nhánh.</p></div></div>;
}

function StatCard({ label, value, tone, onClick }) {
  const tones = {
    neutral: 'border-zinc-200 bg-zinc-50 text-zinc-900',
    amber: 'border-amber-200 bg-amber-50 text-amber-900',
    blue: 'border-blue-200 bg-blue-50 text-blue-900',
    cyan: 'border-cyan-200 bg-cyan-50 text-cyan-900',
    violet: 'border-violet-200 bg-violet-50 text-violet-900',
    green: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    red: 'border-red-200 bg-red-50 text-red-900',
    zinc: 'border-zinc-300 bg-zinc-100 text-zinc-800',
  };
  return (
    <button type="button" onClick={onClick} className={`min-w-0 rounded-xl border px-3 py-3 text-left transition hover:-translate-y-0.5 hover:shadow-sm ${tones[tone]}`}>
      <span className="block text-2xl font-bold tabular-nums">{value}</span>
      <span className="mt-0.5 block break-words text-xs font-semibold leading-snug opacity-75">{label}</span>
    </button>
  );
}

export default AdminAppointmentsView;
