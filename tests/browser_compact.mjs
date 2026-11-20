import fs from 'node:fs/promises';

const base=process.env.APP_BASE_URL||'http://127.0.0.1:8080/';
if(!['127.0.0.1','localhost'].includes(new URL(base).hostname))throw new Error('Use a local application. This suite only signs in and reads pages.');
const baseline=process.env.ERP_CAPTURE_BASELINE==='1';
const directory=process.env.ERP_SCREENSHOT_DIR||'/tmp/stockmgmt-compact-ui/'+(baseline?'before':'after');
await fs.mkdir(directory,{recursive:true});
const version=await(await fetch('http://127.0.0.1:9223/json/version')).json();
const socket=new WebSocket(version.webSocketDebuggerUrl);await new Promise(resolve=>socket.addEventListener('open',resolve,{once:true}));
let next=0,checks=0;const pending=new Map(),errors=[],failed=[];
socket.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text);if(m.method==='Network.responseReceived'&&m.params.response.status>=400)failed.push(m.params.response.url);const waiter=pending.get(m.id);if(waiter){pending.delete(m.id);m.error?waiter.reject(new Error(m.error.message)):waiter.resolve(m.result);}});
function call(method,params={},sessionId){return new Promise((resolve,reject)=>{const id=++next;const timer=setTimeout(()=>{pending.delete(id);reject(new Error('CDP timeout: '+method));},20000);pending.set(id,{resolve:r=>{clearTimeout(timer);resolve(r);},reject:e=>{clearTimeout(timer);reject(e);}});socket.send(JSON.stringify({id,method,params,sessionId}));});}
const target=await call('Target.createTarget',{url:'about:blank'});const {sessionId}=await call('Target.attachToTarget',{targetId:target.targetId,flatten:true});
const send=(method,params)=>call(method,params,sessionId);
await send('Page.enable');await send('Runtime.enable');await send('Network.enable');await send('Network.setCacheDisabled',{cacheDisabled:true});
async function evaluate(expression){const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw new Error(JSON.stringify(r.exceptionDetails));return r.result.value;}
async function waitFor(expression,label){const deadline=Date.now()+15000;while(Date.now()<deadline){if(await evaluate(expression)){checks++;return;}await new Promise(r=>setTimeout(r,80));}throw new Error('Wait failed: '+label);}
async function page(path){await send('Page.navigate',{url:new URL(path,base).href});await waitFor('document.readyState==="complete"&&location.href==='+JSON.stringify(new URL(path,base).href),path);await evaluate('new Promise(resolve=>jQuery(()=>resolve(true)))');check(!await evaluate('/Fatal error|<b>Warning/.test(document.body.innerText)'),path+' PHP error');}
function check(value,label){if(!value)throw new Error(label);checks++;}
async function capture(name){await new Promise(resolve=>setTimeout(resolve,300));const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});await fs.writeFile(directory+'/'+name+'.png',Buffer.from(shot.data,'base64'));}
const measurements=[];
try{
    await send('Network.clearBrowserCookies');await send('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});await page('admin/login.php');
    if(!baseline){
        for(const width of [1366,390,320]){
            await send('Emulation.setDeviceMetricsOverride',{width,height:width===1366?900:844,deviceScaleFactor:1,mobile:width<768});
            check(await evaluate('document.title==="Karyasetu Enterprise Platform"&&document.querySelector(".brand-logo img").naturalWidth>0'),'Login product identity');
            check(await evaluate('document.querySelector(".login-footer").innerText.includes("Karyasetu Tech. Nepal")&&!document.body.innerText.includes("Beacon")'),'Login copyright');
            check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'Login fits at '+width);await capture('login-'+width);
        }
    }
    await evaluate('document.querySelector("[name=username]").value="admin";document.querySelector("[name=password]").value="admin123";document.querySelector("#login-frm").requestSubmit()');
    await waitFor('document.readyState==="complete"&&!!document.querySelector(".erp-header-top")','Login form submission');
    const routes=[['dashboard','home'],['inventory','enterprise/inventory'],['sales','sales'],['purchase-order','transactions/procurement&new=1'],['journal','finance/accounting&new=1'],['reports','enterprise/reports&report=sales_register'],['pos','pos']];
    for(const width of [1366,768,390]){
        await send('Emulation.setDeviceMetricsOverride',{width,height:width===1366?900:844,deviceScaleFactor:1,mobile:width<768});
        for(const [name,route]of routes){
            await page('admin/?page='+route);if(name==='pos')await waitFor('!!document.querySelector("#pos-form-location")','POS location');
            const dimensions=await evaluate(`(()=>{const rect=selector=>{const el=document.querySelector(selector);if(!el)return null;const r=el.getBoundingClientRect();return {top:r.top,height:r.height,bottom:r.bottom,width:r.width};};return {viewport:innerWidth,scroll:document.documentElement.scrollWidth,header:rect('.erp-header-top'),nav:rect('.erp-nav-bar'),kpis:rect('.suite-kpis'),chart:rect('.suite-chart'),control:rect('.form-control:not([type=hidden])'),primary:document.querySelector('.btn-primary')?getComputedStyle(document.querySelector('.btn-primary')).backgroundColor:null};})()`);
            measurements.push({name,width,...dimensions});if(!baseline)check(dimensions.scroll<=width+2,name+' horizontal overflow at '+width);
            if(!baseline){
                check(await evaluate('document.title==="Karyasetu Enterprise Platform"&&document.querySelector(".ns-logo img")?.alt==="Karyasetu Enterprise Platform"'),'Product branding on '+name);
                check(await evaluate('document.querySelector(".erp-footer")?.innerText.includes("Karyasetu Tech. Nepal")&&document.querySelector(".erp-footer").innerText.includes("V3 Update")&&document.querySelectorAll(".erp-footer").length===1'),'Shared copyright and release on '+name);
                check(await evaluate('!!document.querySelector(".erp-main>.erp-footer")'),'Footer is inside app layout');
            }
            if(!baseline&&name==='dashboard'){
                check(dimensions.kpis.bottom<(width===1366?450:width===768?650:900),'Dashboard density at '+width);
                const canvases=await evaluate(`Array.from(document.querySelectorAll('.suite-chart canvas')).map(c=>{const p=c.getContext('2d').getImageData(0,0,c.width,c.height).data;let drawn=0;for(let i=3;i<p.length;i+=4)if(p[i])drawn++;return {width:c.width,height:c.height,drawn};})`);
                check(canvases.length===7&&canvases.every(c=>c.width>70&&c.height>100&&c.drawn>100),'Chart pixels at '+width);
                await evaluate('new Promise(resolve=>jQuery("#suite-preferences-modal").one("shown.bs.modal",()=>resolve(true)).modal("show"))');await waitFor('!!document.querySelector("#suite-preferences-modal.show")','preferences modal');
                await evaluate('new Promise(resolve=>jQuery("#suite-preferences-modal").one("hidden.bs.modal",()=>resolve(true)).modal("hide"))');await waitFor('!document.querySelector(".modal-backdrop")','modal closed');
            }
            if(!baseline&&name==='pos')check(await evaluate('getComputedStyle(document.querySelector(".pos-total-display")).backgroundImage')==='none','POS has no decorative gradient');
            if(!baseline&&name==='purchase-order'){
                await evaluate('document.querySelector("#po-add-line").click()');check(await evaluate('document.querySelectorAll("#po-lines tbody tr").length')===2,'Add order line');
                await evaluate('document.querySelectorAll(".po-remove-line")[1].click()');check(await evaluate('document.querySelectorAll("#po-lines tbody tr").length')===1,'Remove order line');
            }
            if(!baseline&&name==='journal'){
                await evaluate('document.querySelector("#add-journal-line").click()');check(await evaluate('document.querySelectorAll("#journal-lines tbody tr").length')===3,'Add journal line');
                await evaluate('document.querySelectorAll(".remove-line")[2].click()');check(await evaluate('document.querySelectorAll("#journal-lines tbody tr").length')===2,'Remove journal line');
            }
            if(width!==768||name==='dashboard')await capture(name+'-'+width);
        }
        if(!baseline&&width===390){await evaluate('document.querySelector("#erp-mobile-menu-toggle").click()');await waitFor('document.querySelector("#erp-main-menu").classList.contains("is-open")','mobile navigation');}
    }
    if(!baseline){await page('admin/?page=home');await evaluate('document.querySelector(".suite-workspace").dataset.theme="dark"');await capture('dashboard-dark-mobile');}
    check(!errors.length,'Runtime errors: '+JSON.stringify(errors));check(!failed.length,'Failed resources: '+JSON.stringify(failed));
    await fs.writeFile(directory+'/measurements.json',JSON.stringify(measurements,null,2));console.log(JSON.stringify({result:baseline?'Baseline captured':'Compact UI checks passed',checks,directory,dashboard:measurements.filter(row=>row.name==='dashboard')}));
}finally{await call('Target.closeTarget',{targetId:target.targetId});socket.close();}
