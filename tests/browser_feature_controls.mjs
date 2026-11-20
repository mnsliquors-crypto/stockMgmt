import fs from 'node:fs/promises';
const base = process.env.APP_BASE_URL || 'http://127.0.0.1:8081/';
if (new URL(base).port !== '8081') throw new Error('Use the disposable app on port 8081.');
const version = await (await fetch('http://127.0.0.1:9223/json/version')).json();
const socket = new WebSocket(version.webSocketDebuggerUrl);
await new Promise(resolve => socket.addEventListener('open', resolve, {once:true}));
let sequence = 0, checks = 0, sessionId; const pending = new Map(), errors = [];
socket.addEventListener('message', event => {
  const message = JSON.parse(event.data);
  if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
  if (message.method === 'Page.javascriptDialogOpening') command('Page.handleJavaScriptDialog', {accept:true}, sessionId);
  const item = pending.get(message.id); if (item) { pending.delete(message.id); message.error ? item.reject(new Error(message.error.message)) : item.resolve(message.result); }
});
function command(method, params = {}, session) {
  return new Promise((resolve, reject) => {
    const id = ++sequence, timer = setTimeout(() => { pending.delete(id); reject(new Error('CDP timeout: '+method)); }, 20000);
    pending.set(id, {resolve:r=>{clearTimeout(timer);resolve(r)}, reject:e=>{clearTimeout(timer);reject(e)}});
    socket.send(JSON.stringify({id,method,params,sessionId:session}));
  });
}
const target = await command('Target.createTarget', {url:'about:blank'});
({sessionId} = await command('Target.attachToTarget', {targetId:target.targetId,flatten:true}));
const send = (method, params) => command(method, params, sessionId);
async function evaluate(expression) {
  const result = await send('Runtime.evaluate', {expression,awaitPromise:true,returnByValue:true});
  if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
  return result.result.value;
}
function check(ok, label) { if (!ok) throw new Error(label); checks++; }
async function wait(expression, label) {
  const until = Date.now()+15000;
  while (Date.now()<until) { if (await evaluate(expression)) { checks++; return; } await new Promise(resolve=>setTimeout(resolve,80)); }
  throw new Error('Wait failed: '+label);
}
async function page(path) {
  await send('Page.navigate',{url:base+path});
  await wait('document.readyState==="complete" && location.href==='+JSON.stringify(base+path),path);
}
try {
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.clearBrowserCookies');
  await send('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
  await page('admin/login.php');
  const login = await evaluate(`(async()=>{const r=await fetch(${JSON.stringify(base+'classes/Login.php?f=login')},{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:new URLSearchParams({username:'admin',password:'admin123'})});return r.json()})()`);
  check(login.status==='success','Administrator login');
  await page('admin/?page=enterprise/feature_controls');
  await evaluate(`document.querySelector('.user-profile').click()`);
  check(await evaluate(`document.querySelector('.profile-setup-link').closest('.dropdown-menu').classList.contains('show')`),'Setup appears in profile menu');
  check(await evaluate(`!!document.querySelector('.setup-sidebar a[href*="enterprise/feature_controls"]') && ![...document.querySelectorAll('.ns-menu-link')].some(a=>a.textContent.trim().startsWith('Setup'))`),'Setup pages are in sidebar rather than main navigation');
  check(await evaluate(`!!document.querySelector('.setup-sidebar a[aria-current="page"][href*="enterprise/feature_controls"]')`),'Current setup page is highlighted');
  await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
  check(await evaluate(`(()=>{const r=document.querySelector('.profile-setup-link').closest('.dropdown-menu').getBoundingClientRect();return r.left>=0 && r.right<=innerWidth})()`),'Profile Setup fits mobile viewport');
  const menuShot = await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
  await fs.writeFile('/tmp/stockmgmt-profile-setup-390.png',Buffer.from(menuShot.data,'base64'));
  await send('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
  await evaluate(`document.querySelector('.user-profile').click()`);
  await page('admin/?page=system/users');
  check(await evaluate(`!!document.querySelector('.setup-sidebar a[aria-current="page"][href*="system/users"]') && document.body.innerText.includes('List of System Users')`),'User management retains setup sidebar');
  await page('admin/?page=enterprise/feature_controls');
  await wait('document.querySelectorAll("#feature-rows input").length>100','feature switches');
  check(await evaluate('document.body.innerText.includes("do not yet restrict")'),'Preview warning visible');
  await evaluate(`document.querySelector('#retail-roles').click()`);
  await wait(`document.querySelector('#feature-controls-message').innerText.includes('No users were assigned')`,'retail role creation');
  check(await evaluate(`[...document.querySelector('#feature-role').options].some(option=>option.textContent==='Retail Cashier')`),'Retail roles appear in the selector');
  check(await evaluate('document.querySelectorAll(".feature-panel").length>20 && document.querySelectorAll(".feature-panel[open]").length===0'),'Pages start as collapsed accordion panels');
  check(await evaluate('document.querySelector(\'details[data-group="accounting.workspace"] input[data-feature="accounting.journals"]\')!==null'),'Accounting tabs are grouped into their workspace');
  await evaluate('document.querySelector("#feature-search").value="sales.invoices";document.querySelector("#feature-search").dispatchEvent(new Event("input",{bubbles:true}))');
  check(await evaluate(`!!document.querySelector('input[data-feature="sales.invoices"]')`),'Search finds invoice feature');
  check(await evaluate(`document.querySelector('details[data-group="sales.invoices"]').open`),'Search opens the matching panel');
  await evaluate(`document.querySelector('input[data-feature="sales.invoices"]').click();document.querySelector('#feature-search').value='customers';document.querySelector('#feature-search').dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('#feature-search').value='sales.invoices';document.querySelector('#feature-search').dispatchEvent(new Event('input',{bubbles:true}))`);
  check(await evaluate(`!document.querySelector('input[data-feature="sales.invoices"]').checked`),'Search preserves unsaved changes');
  await evaluate(`document.querySelector('#feature-save').click()`);
  await wait('document.querySelector("#feature-controls-message").innerText.includes("Changes saved")','feature save');
  await evaluate(`document.querySelector('input[data-feature="sales.invoices"]').click();document.querySelector('#feature-save').click()`);
  await wait(`document.querySelector('input[data-feature="sales.invoices"]').dataset.original==="1"`,'feature restored');
  await evaluate('document.querySelector("[data-view=roles]").click()');
  await wait(`!!document.querySelector('input[data-feature="sales.invoices"][data-action="view"]')`,'role matrix');
  for (const width of [1366,390,320]) {
    await send('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<768});
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'No page overflow at '+width);
    const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
    await fs.writeFile('/tmp/stockmgmt-feature-controls-'+width+'.png',Buffer.from(shot.data,'base64'));
  }
  check(errors.length===0,'Browser errors: '+JSON.stringify(errors));
  console.log('Passed '+checks+' feature-control browser checks.');
} finally { await command('Target.closeTarget',{targetId:target.targetId}); socket.close(); }
