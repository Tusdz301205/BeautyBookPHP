export function policyCacheFresh(entry,key,now=Date.now()){
 if(!entry||entry.key!==key||now-entry.readAt>=30000)return false;
 const restriction=entry.value?.restriction;
 return !restriction?.active||new Date(restriction.ends_at).getTime()>now;
}
export const shouldReloadAvailability=error=>[403,409,422].includes(error?.status);
