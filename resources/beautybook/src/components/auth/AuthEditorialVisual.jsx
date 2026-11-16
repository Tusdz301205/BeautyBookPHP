import React from 'react';
export default function AuthEditorialVisual({mode='login',audience='customer'}){
 const register=mode==='register',business=audience==='business';
 return <div className={'bb-auth-magazine '+(register?'bb-auth-magazine--register':' ')}>
  <div className="bb-auth-magazine__cover"><img className="bb-auth-magazine__photo" src="/images/beautybook/auth/glowme-spa-editorial-v3.png" alt="Không gian spa thư thái với khăn mềm, đá mát-xa và ánh sáng tự nhiên" width="1672" height="941" fetchPriority="high"/></div>
  <div className="bb-auth-magazine__headline"><span className="bb-auth-magazine__eyebrow">GÓC LÀM ĐẸP BEAUTYBOOK</span><p className="bb-auth-magazine__title"><em>{register?'một':'khám phá'}</em> <strong>{register?'khởi đầu mới.':'nét rạng rỡ riêng.'}</strong></p><p className="bb-auth-magazine__description">{business?'Chăm chút từng trải nghiệm. Kết nối từng niềm tin.':register?'Bắt đầu hành trình chăm sóc bản thân, theo cách của bạn.':'Một chút thời gian cho mình. Một phiên bản rạng rỡ hơn.'}</p></div>
 </div>;
}
