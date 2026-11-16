import fs from 'node:fs';
import path from 'node:path';
export function runtimeEnvironment(){
 const env={...process.env,PYTHONUTF8:'1',OMP_NUM_THREADS:process.env.OMP_NUM_THREADS||'2',MKL_NUM_THREADS:process.env.MKL_NUM_THREADS||'2'};
 if(process.platform==='win32'&&!env.JAVA_HOME){
  const dir=path.join(process.env.ProgramFiles||'C:/Program Files','Java');
  if(fs.existsSync(dir))for(const name of fs.readdirSync(dir).sort().reverse())if(fs.existsSync(path.join(dir,name,'bin/javac.exe'))){env.JAVA_HOME=path.join(dir,name);break;}
 }
 return env;
}
