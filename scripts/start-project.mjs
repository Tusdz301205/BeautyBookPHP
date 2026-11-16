import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
const root=path.resolve(import.meta.dirname,'..');
if(fs.existsSync(path.join(root,'.env')))process.loadEnvFile(path.join(root,'.env'));
function run(command,args){const result=spawnSync(command,args,{cwd:root,stdio:'inherit',windowsHide:true});if(result.error||result.status!==0)throw new Error('Không khởi động được dịch vụ. Xem thông báo phía trên.');}
try{
 run(process.execPath,['scripts/ml/background.mjs','start']);
 const compose=['compose','-f','docker-compose.laravel.yml','--profile','ml'];
 run('docker',[...compose,'up','-d','--wait']);
 run('docker',[...compose,'exec','-T','web','nginx','-s','reload']);
 console.log('BeautyBook: http://localhost:8000/beautybook');
 console.log('AI chạy nền; kiểm tra bằng npm run ai:status và php artisan absa:check trong container.');
}catch(error){console.error(error.message);process.exitCode=1;}
