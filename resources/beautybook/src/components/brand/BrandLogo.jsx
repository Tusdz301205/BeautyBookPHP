import { Sparkles } from 'lucide-react';
import '../../styles/glowme/brand.css';

export function BrandMark({ size = 36, className = '' }) {
  return <Sparkles className={`bb-brand-mark ${className}`} size={size} strokeWidth={1.4} aria-hidden="true" />;
}
export function BrandWordmark({ className = '' }) {
  return <span className={`bb-brand-wordmark ${className}`}>BeautyBook</span>;
}
export function BrandLogo({ size = 36, subtitle, className = '' }) {
  return <span className={`bb-brand ${className}`}><BrandMark size={size} /><span className="bb-brand__text"><BrandWordmark />{subtitle && <small>{subtitle}</small>}</span></span>;
}
export default BrandLogo;
