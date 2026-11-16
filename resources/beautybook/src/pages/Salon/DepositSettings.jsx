import {useAuthStore} from '../../store/authStore';
import {OwnerDirectDeposits} from './WalletPanels';
import {Page,PageHeader,Card} from '../../components/ui';

export default function DepositSettings(){
 const user=useAuthStore(s=>s.user);
 const businessId=user?.scopes?.find(scope=>scope.code==='BUSINESS_OWNER'&&scope.businessId)?.businessId;
 return <Page><PageHeader title="Nhận cọc 30%"/>
  <Card className="mb-6 p-5"><ol className="grid gap-4 md:grid-cols-3"><li><strong>1. Thêm tài khoản ngân hàng</strong><p className="mt-2 text-sm">Nhập tài khoản của cơ sở. Chọn chế độ thử nghiệm để dùng giao dịch giả lập hoặc chế độ nhận tiền thật.</p></li><li><strong>2. Kết nối SePay</strong><p className="mt-2 text-sm">Tạo webhook tiền vào, dữ liệu JSON và HMAC-SHA256 bằng URL và khóa bí mật được cấp sau khi lưu.</p></li><li><strong>3. Xác minh và bật nhận cọc</strong><p className="mt-2 text-sm">Gửi giao dịch có mã xác minh. Khi kết nối đã xác minh, bật nhận cọc để khách thấy QR sau khi đặt lịch.</p></li></ol><p className="mt-4 text-sm">Webhook cần địa chỉ HTTPS công khai; SePay không gọi được localhost. <a href="https://docs.sepay.vn/tich-hop-webhooks.html" target="_blank" rel="noreferrer" className="underline">Hướng dẫn SePay</a></p></Card>
  <OwnerDirectDeposits businessId={businessId}/>
 </Page>;
}
