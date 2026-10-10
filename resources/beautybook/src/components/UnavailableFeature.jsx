import { Link } from 'react-router-dom';
import { Card, Page, PageHeader } from './ui';

export default function UnavailableFeature({ finance = false, home = '/customer/profile' }) {
  return <Page><PageHeader title={finance ? 'Tài chính & hoàn tiền chưa khả dụng' : 'Trang không còn khả dụng'} description={finance ? 'Khu vực này đang tạm đóng. Bạn có thể tiếp tục quản lý lịch hẹn và các chức năng khác.' : 'Tính năng yêu cầu xử lý dữ liệu đã được gỡ khỏi sản phẩm.'} /><Card className="p-5"><Link className="inline-flex min-h-11 items-center font-semibold text-[var(--bb-brand-strong)] underline" to={home}>Quay lại khu vực của bạn</Link></Card></Page>;
}
