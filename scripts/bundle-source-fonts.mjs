import fs from 'node:fs/promises';
import crypto from 'node:crypto';
import { chromium } from 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs';
const browser=await chromium.launch({channel:'chrome',headless:true});
try {
  const context=await browser.newContext();const page=await context.newPage();
  let stylesheet;
  page.on('response',async response=>{if(response.url().startsWith('https://fonts.googleapis.com/css2?'))stylesheet={url:response.url(),css:await response.text()};});
  await page.goto('http://localhost:18081/',{waitUntil:'networkidle'});await page.evaluate(()=>document.fonts.ready);
  if(!stylesheet?.css.includes('@font-face'))throw new Error('Original Google Fonts stylesheet was not captured');
  const directory='public/beautybook-fonts';await fs.mkdir(directory,{recursive:true});
  let css=stylesheet.css;const manifest=[];
  for(const url of [...new Set([...css.matchAll(/url\((https:\/\/fonts\.gstatic\.com\/[^)]+)\)/g)].map(match=>match[1]))]) {
    const response=await context.request.get(url);if(!response.ok())throw new Error(`Font download ${response.status()}`);
    const bytes=await response.body();const hash=crypto.createHash('sha256').update(bytes).digest('hex');const name=hash+'.woff2';
    await fs.writeFile(`${directory}/${name}`,bytes);css=css.replaceAll(url,`/beautybook-fonts/${name}`);
    manifest.push({sourceUrl:url,path:`${directory}/${name}`,sha256:hash,bytes:bytes.length});
  }
  if(!manifest.length||!css.includes('U+1EA0-1EF9'))throw new Error('Required Vietnamese unicode subset missing');
  await fs.writeFile('resources/beautybook/src/styles/fonts.css',`/* Self-hosted exact Google Fonts subsets requested by original BeautyBook. */\n${css}`);
  await fs.writeFile(`${directory}/source-manifest.json`,JSON.stringify({stylesheetUrl:stylesheet.url,fonts:manifest},null,2));
  await fs.writeFile('docs/course/evidence/2026-10-07/reference-runtime/original-google-fonts.css',stylesheet.css);
  console.log(JSON.stringify({fonts:manifest.length,bytes:manifest.reduce((sum,font)=>sum+font.bytes,0),vietnameseSubset:true}));
} finally {await browser.close();}
