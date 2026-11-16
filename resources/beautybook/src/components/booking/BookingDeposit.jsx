import React from 'react';
import WalletDepositNotice from '../customer/WalletDepositNotice';
export function DepositPreview({deposit}){if(!deposit)return null;return <WalletDepositNotice readiness={deposit.readiness||{required:true,direct:deposit.fundingSource!=='SIMULATED_WALLET',ok:true}} terms={deposit.unavailable?null:deposit.seriesTerms||deposit} wallet={deposit.wallet}/>;}
