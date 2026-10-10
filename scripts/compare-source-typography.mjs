import fs from 'node:fs/promises';
import { chromium } from 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs';
const browser=await chromium.launch({channel:'chrome',headless:true});
const results=[];
try {
  for(const [name,url] of [['reference','http://localhost:18081/'],['laravel','http://localhost:8000/beautybook/']]) {
    const page=await browser.newPage({viewport:{width:1440,height:900}});
    const fontRequests=[];page.on('response',response=>{if(/fonts\.(googleapis|gstatic)\.com|\.woff2?/.test(response.url()))fontRequests.push({url:response.url(),status:response.status()});});
    await page.goto(url,{waitUntil:'networkidle'});await page.evaluate(()=>document.fonts.ready);
    const cdp=await page.context().newCDPSession(page);await cdp.send('DOM.enable');await cdp.send('CSS.enable');
    const {root}=await cdp.send('DOM.getDocument');const {nodeId}=await cdp.send('DOM.querySelector',{nodeId:root.nodeId,selector:'.bb-home-hero h1'});
    const fonts=await cdp.send('CSS.getPlatformFontsForNode',{nodeId});
    const measurements=await page.locator('.bb-home-hero h1').evaluate(element=>{const css=getComputedStyle(element);const range=document.createRange();range.selectNodeContents(element);return {text:element.textContent,fontFamily:css.fontFamily,fontWeight:css.fontWeight,fontSize:css.fontSize,lineHeight:css.lineHeight,letterSpacing:css.letterSpacing,textWidth:range.getBoundingClientRect().width,faces:[...document.fonts].filter(font=>font.status==='loaded').map(font=>({family:font.family,weight:font.weight,unicodeRange:font.unicodeRange}))};});
    results.push({name,url,...measurements,...fonts,fontRequests});
    await page.screenshot({path:`docs/course/evidence/2026-10-07/reference-runtime/${name}-typography.png`});await page.close();
  }
  await fs.writeFile('docs/course/evidence/2026-10-07/reference-runtime/typography-comparison.json',JSON.stringify(results,null,2));
  console.log(JSON.stringify(results.map(({name,textWidth,fonts})=>({name,textWidth,fonts}))));
} finally {await browser.close();}
