import {useEffect,useState} from 'react';
import {Link,useSearchParams} from 'react-router-dom';
import {publicCatalogApi} from '../../api/publicCatalogApi';
import {Card,EmptyState,ErrorState,Skeleton} from '../../components/ui';
import CustomerBookingForm from './CustomerBookingForm';
import CustomerRecurringForm from './CustomerRecurringForm';
export default function CustomerBooking(){
 const [query]=useSearchParams(),serviceId=query.get('serviceId'),comboId=query.get('comboId'),repeat=query.get('repeat')==='1';
 const [state,setState]=useState({loading:true}),[revision,reload]=useState(0);const valid=Boolean(serviceId)!==Boolean(comboId);
 useEffect(()=>{let active=true;if(!valid)return;setState({loading:true});(async()=>{try{const kind=serviceId?'service':'combo',item=await publicCatalogApi[kind](serviceId||comboId),branchId=String(item.branchId??item.branch?.id);const branch=await publicCatalogApi.branch(branchId);if(active)setState({value:{kind,item:{...item,id:String(item.id),branchId},branch:{...branch,id:branchId}},loading:false});}catch(error){if(active)setState({error:error.message});}})();return()=>{active=false;};},[serviceId,comboId,revision,valid]);
 return <div className="bb-booking-page py-6"><div className="bb-booking-page__navigation"><Link to="/explore">← Quay lại tìm dịch vụ</Link><Link to="/customer/recurring">Kế hoạch định kỳ của tôi</Link></div>{!valid?<Card><EmptyState title="Bạn muốn đặt dịch vụ nào?" description="Chọn một dịch vụ hoặc gói dịch vụ từ trang tìm dịch vụ." action={<Link to="/explore">Tìm dịch vụ</Link>}/></Card>:state.loading?<Card><Skeleton rows={8}/></Card>:state.error?<Card><ErrorState message={state.error} onRetry={()=>reload(r=>r+1)}/></Card>:<><nav className="my-5 flex gap-3" aria-label="Loại lịch hẹn"><Link className="bb-home-button" aria-current={!repeat?'page':undefined} to={'?'+new URLSearchParams(serviceId?{serviceId}:{comboId})}>Một lần</Link><Link className="bb-home-button" aria-current={repeat?'page':undefined} to={'?'+new URLSearchParams({...serviceId?{serviceId}:{comboId},repeat:'1'})}>Định kỳ</Link></nav>{repeat?<CustomerRecurringForm {...state.value}/>:<CustomerBookingForm key={JSON.stringify([serviceId,comboId,revision])} {...state.value}/>}</>}</div>;
}
