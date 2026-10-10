import fs from 'node:fs';
import path from 'node:path';
import { parse } from '@babel/parser';

const root='resources/beautybook/src';
const source=fs.readFileSync(`${root}/api/apiClient.js`,'utf8');
const ast=parse(source,{sourceType:'module',plugins:['jsx']});
const reachable=new Set();
function visitFile(file){
 if(reachable.has(file)||!fs.existsSync(file))return;
 reachable.add(file);
 const text=fs.readFileSync(file,'utf8');
 for(const match of text.matchAll(/(?:from\s*|import\s*\(\s*|import\s*)['"]([^'"]+)['"]/g)){
  if(!match[1].startsWith('.'))continue;
  const base=path.resolve(path.dirname(file),match[1]);
  const target=[base,base+'.js',base+'.jsx',path.join(base,'index.js'),path.join(base,'index.jsx')].find(f=>fs.existsSync(f)&&fs.statSync(f).isFile());
  if(target)visitFile(target);
 }
}
visitFile(path.resolve(root,'App.jsx'));
const activeText=[...reachable].filter(f=>!f.endsWith('apiClient.js')).map(f=>fs.readFileSync(f,'utf8')).join('\n');
function alternatives(node){
 if(!node)return [''];
 if(node.type==='StringLiteral')return[node.value];
 if(node.type==='TemplateLiteral'){
  let out=[node.quasis[0].value.cooked];
  node.expressions.forEach((e,i)=>{out=out.flatMap(s=>alternatives(e).map(x=>s+x+node.quasis[i+1].value.cooked));});
  return out;
 }
 if(node.type==='ConditionalExpression')return [...alternatives(node.consequent),...alternatives(node.alternate)];
 if(node.type==='BinaryExpression'&&node.operator==='+')return alternatives(node.left).flatMap(a=>alternatives(node.right).map(b=>a+b));
 return ['1'];
}
const inventory=[];
function walk(node,group=null,method=null){
 if(!node||typeof node!=='object')return;
 if(node.type==='VariableDeclarator'&&node.id?.name?.endsWith('Api'))group=node.id.name;
 if(node.type==='ObjectProperty'&&group&&['ArrowFunctionExpression','FunctionExpression'].includes(node.value?.type))method=node.key.name??node.key.value;
 if(group&&node.type==='CallExpression'&&node.callee?.name==='request'){
  const options=node.arguments[1];
  const verb=options?.properties?.find(p=>p.key?.name==='method')?.value?.value??'GET';
  inventory.push({group,operation:method,method:verb,endpoints:[...new Set(alternatives(node.arguments[0]).map(s=>s.split('?')[0]))],line:node.loc.start.line,active:!!(group&&method&&new RegExp(`\\b${group}\\.${method}\\b`).test(activeText))});
 }
 for(const [key,value] of Object.entries(node))if(!['loc','start','end'].includes(key)){if(Array.isArray(value))value.forEach(n=>walk(n,group,method));else if(value&&typeof value==='object')walk(value,group,method);}
}
walk(ast);
inventory.push({group:'authApi',operation:'refresh',method:'POST',endpoints:['/auth/refresh'],active:true});
const routes=JSON.parse(fs.readFileSync('resources/beautybook/laravel-api-routes.json','utf8').replace(/^\uFEFF/,''));
for(const row of inventory){row.missing=row.endpoints.filter(endpoint=>!routes.some(r=>r.method.split('|').includes(row.method)&&new RegExp('^'+r.uri.replace(/^api\/v1/,'').replace(/\{[^}]+\}/g,'[^/]+')+'$').test(endpoint)));}
const summary={reachableFiles:reachable.size,operations:inventory.length,activeOperations:inventory.filter(i=>i.active).length,missingActive:inventory.filter(i=>i.active&&i.missing.length),missingInactive:inventory.filter(i=>!i.active&&i.missing.length),inventory};
fs.writeFileSync('resources/beautybook/api-contract-audit.json',JSON.stringify(summary,null,2));
console.log(JSON.stringify({reachableFiles:summary.reachableFiles,operations:summary.operations,activeOperations:summary.activeOperations,missingActive:summary.missingActive,missingInactive:summary.missingInactive},null,2));
