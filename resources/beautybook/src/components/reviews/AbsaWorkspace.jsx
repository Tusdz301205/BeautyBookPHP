import {useEffect, useState} from 'react';
import {Store} from 'lucide-react';
import {branchesApi} from '../../api/apiClient';
import {useAuthStore} from '../../store/authStore';
import {roleAt} from '../../utils/authScope';
import {Button, Card, EmptyState, ErrorState, Select, Skeleton} from '../ui';
import ReviewList from './ReviewList';

export default function AbsaWorkspace({businessId}) {
  const user = useAuthStore(s => s.user), token = useAuthStore(s => s.accessToken);
  const [directory, setDirectory] = useState({items: [], loading: true, error: ''});
  const [branchId, setBranchId] = useState(''), [version, setVersion] = useState(0);
  useEffect(() => {
    let active = true;
    const current = () => {
      const auth = useAuthStore.getState();
      return active && auth.accessToken === token && auth.user?.id === user?.id
        && auth.activeContext().tenantId === businessId && roleAt(auth.user, 'BUSINESS_OWNER', businessId);
    };
    setDirectory({items: [], loading: true, error: ''}); setBranchId('');
    branchesApi.getAccessible().then(result => {
      if (!current()) return;
      const items = (Array.isArray(result) ? result : result.data || []).filter(b => b.businessId === businessId)
        .sort((a, b) => a.name.localeCompare(b.name, 'vi'));
      setDirectory({items, loading: false, error: ''});
      if (items.length === 1) setBranchId(items[0].id);
    }).catch(error => { if (current()) setDirectory({items: [], loading: false, error: error.message}); });
    return () => {active = false;};
  }, [businessId, user?.id, token, version]);
  const branch = directory.items.find(b => b.id === branchId);
  return <>
    <Card className="bb-branch-workspace-scope" aria-label="Chi nhánh đang quản lý">
      <span className="bb-branch-workspace-scope__icon" aria-hidden="true"><Store size={20}/></span>
      <div className="bb-branch-workspace-scope__summary"><p>Chi nhánh đang quản lý</p><span>{branch?.name || 'Chọn chi nhánh để bắt đầu'}</span></div>
      <div className="bb-branch-workspace-scope__select"><Select aria-label="Chọn chi nhánh quản lý" value={branchId} disabled={directory.loading} onChange={e => setBranchId(e.target.value)}>
        <option value="">Chọn chi nhánh</option>{directory.items.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
      </Select></div>
      {branch && directory.items.length > 1 && <Button size="sm" variant="ghost" onClick={() => setBranchId('')}>Bỏ chọn</Button>}
    </Card>
    {directory.loading ? <Skeleton rows={6}/> : directory.error ? <Card><ErrorState message={directory.error} onRetry={() => setVersion(v => v + 1)}/></Card>
      : branch ? <ReviewList key={branch.id} businessId={businessId} branchId={branch.id}/>
        : <Card><EmptyState icon={Store} title={directory.items.length ? 'Chọn chi nhánh để xem và quản lý' : 'Chưa có chi nhánh để quản lý'}/></Card>}
  </>;
}
