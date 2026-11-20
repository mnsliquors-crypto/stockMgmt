import fs from 'node:fs/promises';

const base=process.env.APP_BASE_URL||'http://127.0.0.1:8081/';
if(new URL(base).port!=='8081')throw new Error('Use the disposable app on port 8081.');
const screenshots=process.env.ERP_SCREENSHOT_DIR||'/tmp/stockmgmt-enterprise-browser/screenshots';
await fs.mkdir(screenshots,{recursive:true});
const version=await(await fetch((process.env.CHROME_DEBUG_URL||'http://127.0.0.1:9223')+'/json/version')).json();
const socket=new WebSocket(version.webSocketDebuggerUrl);
await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
let sequence=0,checks=0;const pending=new Map(),errors=[],failedResources=[];
socket.addEventListener('message',event=>{
    const message=JSON.parse(event.data);
    if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.text+': '+message.params.exceptionDetails.exception?.description);
    if(message.method==='Network.responseReceived'&&message.params.response.status>=400)failedResources.push(message.params.response.url+' '+message.params.response.status);
    const waiter=pending.get(message.id);if(waiter){pending.delete(message.id);message.error?waiter.reject(new Error(message.error.message)):waiter.resolve(message.result);}
});
function command(method,params={},sessionId){return new Promise((resolve,reject)=>{const id=++sequence;const timer=setTimeout(()=>{pending.delete(id);reject(new Error('CDP timeout '+method));},20000);pending.set(id,{resolve:v=>{clearTimeout(timer);resolve(v);},reject:e=>{clearTimeout(timer);reject(e);}});socket.send(JSON.stringify({id,method,params,sessionId}));});}
const target=await command('Target.createTarget',{url:'about:blank'});
const {sessionId}=await command('Target.attachToTarget',{targetId:target.targetId,flatten:true});
const send=(method,params)=>command(method,params,sessionId);
await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
async function evaluate(expression){const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.exceptionDetails)throw new Error(JSON.stringify(r.exceptionDetails));return r.result.value;}
async function waitFor(expression,label){const deadline=Date.now()+20000;while(Date.now()<deadline){if(await evaluate(expression)){checks++;return;}await new Promise(r=>setTimeout(r,80));}throw new Error('Wait failed '+label+': '+await evaluate('document.body.innerText.slice(-1400)'));}
async function navigate(path){await send('Page.navigate',{url:base+path});await waitFor('document.readyState==="complete"&&location.href==='+JSON.stringify(new URL(path,base).href),path);await evaluate('new Promise(resolve=>jQuery(()=>resolve(true)))');if(await evaluate('/Fatal error|Uncaught |Warning:/.test(document.body.innerText)'))throw new Error('PHP error '+path);}
function check(ok,label){if(!ok)throw new Error(label);checks++;}
async function shot(name){const s=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});await fs.writeFile(screenshots+'/'+name+'.png',Buffer.from(s.data,'base64'));}
async function layout(label){const d=await evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth})');check(d.scroll<=d.width+2,label+' overflow '+JSON.stringify(d));}
try{
    await send('Network.clearBrowserCookies');await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});
    await navigate('admin/login.php');
    check(await evaluate(`(async()=>{const r=await fetch(${JSON.stringify(base+'classes/Login.php?f=login')},{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:new URLSearchParams({username:'admin',password:'admin123'})});return(await r.json()).status==='success';})()`),'Admin login');
    await navigate('admin/?page=home');
    await waitFor('typeof Chart!=="undefined"&&Object.keys(Chart.instances).length===7','dashboard charts');
    await new Promise(r=>setTimeout(r,350));
    const pixels=await evaluate(`Array.from(document.querySelectorAll('.suite-chart canvas')).map(canvas=>{const p=canvas.getContext('2d').getImageData(0,0,canvas.width,canvas.height).data;let count=0;for(let i=3;i<p.length;i+=4)if(p[i])count++;return {id:canvas.id,width:canvas.width,height:canvas.height,pixels:count};})`);
    await shot('dashboard-desktop');
    check(pixels.length===7&&pixels.every(c=>c.width>100&&c.height>100&&c.pixels>100),'All charts have rendered pixels: '+JSON.stringify(pixels));
    await layout('Dashboard desktop');
    await evaluate('localStorage.setItem("erp_search_history",JSON.stringify(["<img src=x onerror=window.historyInjected=true>"]));document.querySelector("#ns-global-search").focus()');
    check(await evaluate('!window.historyInjected&&!document.querySelector("#ns-search-results img")&&document.querySelector("#ns-search-results").innerText.includes("<img")'),'Search history is rendered as text');
    await evaluate('localStorage.removeItem("erp_search_history");jQuery("#ns-search-results").hide();true');
    const originalTheme=await evaluate('document.querySelector(".suite-workspace").dataset.theme');
    await evaluate('document.querySelector("#suite-theme-toggle").click()');await waitFor('document.querySelector(".suite-workspace").dataset.theme!=='+JSON.stringify(originalTheme),'save dashboard theme');
    await shot('dashboard-dark');await navigate('admin/?page=home');check(await evaluate('document.querySelector(".suite-workspace").dataset.theme')!==originalTheme,'Theme survives reload');
    await evaluate('document.querySelector("#suite-theme-toggle").click()');await waitFor('document.querySelector(".suite-workspace").dataset.theme==='+JSON.stringify(originalTheme),'restore theme');
    await evaluate('jQuery("#suite-preferences-modal").modal("show");document.querySelector("#suite-preferences-modal input[value=hourly]").checked=true;document.querySelector("#suite-preferences-form").requestSubmit()');
    await waitFor('document.querySelector("[data-widget=hourly]").hidden','hide hourly');
    await evaluate('jQuery("#suite-preferences-modal").modal("show");document.querySelector("#suite-preferences-modal input[value=hourly]").checked=false;document.querySelector("#suite-preferences-form").requestSubmit()');
    await waitFor('!document.querySelector("[data-widget=hourly]").hidden','restore hourly');
    const routes=['enterprise/inventory','enterprise/inventory&view=locations','enterprise/inventory&view=packaging','enterprise/inventory&view=transfers','enterprise/promotions','enterprise/promotions&new=1','enterprise/activities','enterprise/activities&view=calendar','enterprise/reports&report=sales_register','enterprise/reports&report=pos_summary','enterprise/reports&report=break_even_payback','enterprise/roles','enterprise/fiscal_years','enterprise/credits','enterprise/health','enterprise/receipts'];
    for(const route of routes){await navigate('admin/?page='+route);await layout(route+' desktop');}
    await navigate('admin/?page=enterprise/reports&report=sales_register');
    await evaluate('document.querySelector("#suite-report-search").value="aging";document.querySelector("#suite-report-search").dispatchEvent(new Event("input"))');
    check(await evaluate('Array.from(document.querySelectorAll(".suite-report-link")).filter(a=>!a.hidden).length')===2,'Report search filters catalog');
    await evaluate('window.print=()=>{window.printed=true};document.querySelector(".suite-print").click()');check(await evaluate('window.printed'),'Report print');await shot('report-desktop');
    await navigate('admin/?page=enterprise/reports&report=customer_statement&party_id=1');
    await evaluate('window.open=(url)=>{window.shareDraft=url};document.querySelector("#suite-share-phone").value="+977 9800000000";document.querySelector("#suite-share-open").click()');
    check(await evaluate('window.shareDraft.startsWith("https://wa.me/9779800000000?text=")'),'WhatsApp composer uses reviewed draft without sending');
    await navigate('admin/?page=enterprise/reports&report=break_even_payback&investment=1000&period_cash_flow=200');check(await evaluate('document.querySelector(".suite-report-output").innerText.includes("5.00")'),'Payback assumptions render');
    await navigate('admin/?page=enterprise/inventory&view=locations');
    const branch='Browser Branch '+Date.now();
    await evaluate('document.querySelector("#location-code").value="BR-'+Date.now()+'";document.querySelector("#location-name").value='+JSON.stringify(branch)+';document.querySelector(".suite-form").requestSubmit()');
    await waitFor('document.readyState==="complete"&&document.querySelector(".suite-data-table,.suite-form")&&document.body?.innerText.includes('+JSON.stringify(branch)+')','location saved');
    const branchId=await evaluate('Array.from(document.querySelectorAll("table tbody tr")).find(r=>r.innerText.includes('+JSON.stringify(branch)+')).querySelector("a").href.match(/id=(\\d+)/)[1]');
    await navigate('admin/?page=enterprise/inventory&view=packaging');
    const itemId=await evaluate('Array.from(document.querySelector("#packaging-item").options).find(o=>o.text==="Enterprise Browser Item").value');
    await evaluate('jQuery("#packaging-item").val('+JSON.stringify(itemId)+').trigger("change");true');
    check(await evaluate('document.querySelector("#packaging-factor").value')==='12.0000','Packaging loads stored case factor');
    await navigate('admin/?page=transactions/procurement&new=1');
    await waitFor('!!document.querySelector("#purchase-order-form-location")','case order options');
    await evaluate('const vendor=Array.from(document.querySelector("#po-vendor").options).find(o=>o.text==="Enterprise Browser Supplier");jQuery("#po-vendor").val(vendor.value).trigger("change");jQuery(".po-item").val('+JSON.stringify(itemId)+').trigger("change");true');
    await waitFor('document.querySelector(".erp-order-unit")?.options.length===2','order case units');
    await evaluate('jQuery(".erp-order-unit").val("case").trigger("change");document.querySelector(".po-quantity").value="2";document.querySelector("#po-notes").value="Browser case order";document.querySelector("#purchase-order-form").requestSubmit()');
    await waitFor('document.readyState==="complete"&&new URL(location.href).searchParams.has("id")&&document.querySelector(".erp-order-unit")?.value==="case"','saved order retains case units');
    check(await evaluate('document.querySelector(".po-price").value')==='110.0000','Saved order retains case purchase price');
    await navigate('admin/?page=enterprise/inventory&view=transfers');
    await evaluate('jQuery("#transfer-from").val("1").trigger("change");jQuery("#transfer-to").val('+JSON.stringify(branchId)+').trigger("change");jQuery(".line-item").val('+JSON.stringify(itemId)+').trigger("change");jQuery(".line-unit").val("case").trigger("change");document.querySelector(".line-quantity").value="2";document.querySelector("#transfer-notes").value="Browser case transfer";document.querySelector(".suite-lines-form").requestSubmit()');
    await waitFor('document.readyState==="complete"&&Array.from(document.querySelectorAll("table tbody tr")).some(r=>r.innerText.includes('+JSON.stringify(branch)+'))','case transfer saved');
    await navigate('admin/?page=enterprise/promotions&new=1');
    const offer='Browser Offer '+Date.now();
    await evaluate('document.querySelector("#promotion-code").value="OFFER-'+Date.now()+'";document.querySelector("#promotion-name").value='+JSON.stringify(offer)+';document.querySelector("#promotion-starts_at").value="2199-01-01T00:00";document.querySelector("#promotion-ends_at").value="2199-02-01T00:00";jQuery("#promotion-all-locations").prop("checked",false).trigger("change");jQuery("#promotion-locations").val(['+JSON.stringify(branchId)+']).trigger("change");jQuery(".line-item").val('+JSON.stringify(itemId)+').trigger("change");document.querySelector(".suite-promotion-form").requestSubmit()');
    await waitFor('document.readyState==="complete"&&location.search==="?page=enterprise/promotions"&&document.body?.innerText.includes('+JSON.stringify(offer)+')','promotion saved');
    await navigate('admin/?page=enterprise/activities&new=1');
    const task='Browser receiving task '+Date.now();
    await evaluate('document.querySelector("#activity-title").value='+JSON.stringify(task)+';document.querySelector(".suite-form").requestSubmit()');
    await waitFor('document.readyState==="complete"&&location.search==="?page=enterprise/activities"&&document.body?.innerText.includes('+JSON.stringify(task)+')','activity saved');
    await navigate('admin/?page=enterprise/activities&view=calendar');await waitFor('!!document.querySelector(".fc-event")','calendar event');await shot('calendar-desktop');
    await navigate('admin/?page=enterprise/assistant');
    await evaluate('document.querySelector("#suite-assistant-form textarea").value="Bought 2 cases of Enterprise Browser Item from Enterprise Browser Supplier for 220";document.querySelector("#suite-assistant-form").requestSubmit()');
    await waitFor('!document.querySelector("#suite-assistant-result").hidden','local note parsed');check(await evaluate('document.querySelector("#suite-assistant-fields").innerText.includes("Enterprise Browser Item")'),'Assistant matches real item');
    await navigate('admin/?page=pos');await waitFor('!!document.querySelector("#pos-form-location")','POS locations');
    await evaluate('const input=document.querySelector("#item_ss .ss-input");input.value="ERPBROWSERCASE";input.dispatchEvent(new Event("input",{bubbles:true}))');
    await waitFor('!!document.querySelector("#item_ss .ss-item")','case barcode results');
    await evaluate('document.querySelector("#item_ss .ss-item").click();document.querySelector("#qty").value="1";document.querySelector("#add_to_cart_btn").click()');
    await waitFor('document.querySelector("#pos-cart-table .erp-line-unit")?.value==="case"&&!document.querySelector("#pos-form button[type=submit]").disabled','case quote');
    check(await evaluate('document.querySelector("#pos-cart-table .price-input").value')==='150.0000','Configured case price replaces typed price');
    await evaluate('const cash=Array.from(document.querySelectorAll(".paid-amount-input")).find(i=>i.dataset.type==="Cash"||i.dataset.name.toLowerCase()==="cash");cash.value="200";jQuery(cash).trigger("input").trigger("change");true');
    await shot('pos-case-desktop');await evaluate('document.querySelector("#pos-form").requestSubmit()');
    await waitFor('!!document.querySelector("#return-overlay")','checkout completed');
    check(await evaluate('document.querySelector("#return-overlay").innerText.includes("50.00")'),'Server change shown');check(await evaluate('!!document.querySelector("#return-overlay a[href*=receipts]")'),'Receipt link');await shot('checkout-desktop');
    await evaluate('document.querySelector("#close-overlay-btn").click()');await waitFor('!document.querySelector("#return-overlay")','next customer');
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    for(const route of ['home',...routes,'enterprise/activities&new=1','enterprise/assistant','pos']){await navigate('admin/?page='+route);await layout(route+' mobile');if(['home','enterprise/inventory&view=transfers','enterprise/reports&report=sales_register','enterprise/activities&view=calendar','pos'].includes(route))await shot(route.replace(/[^a-z0-9]/gi,'-')+'-mobile');}
    await send('Network.clearBrowserCookies');await navigate('admin/login.php');
    check(await evaluate(`(async()=>{const r=await fetch(${JSON.stringify(base+'classes/Login.php?f=login')},{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:new URLSearchParams({username:'enterprise_browser_reader',password:'EnterpriseBrowser123!'})});return(await r.json()).status==='success';})()`),'Restricted staff login');
    await send('Page.navigate',{url:base+'admin/'});await waitFor('document.readyState==="complete"&&location.search==="?page=enterprise/inventory"&&!!document.querySelector(".suite-workspace")','restricted landing');await evaluate('new Promise(resolve=>jQuery(()=>resolve(true)))');
    check(await evaluate('!document.querySelector(".erp-nav-bar a[href*=accounting]")&&!document.querySelector("#globalCreateNew")&&getComputedStyle(document.querySelector("#ns-global-search").parentElement).display==="none"'),'Navigation omits disallowed actions');
    check(!errors.length,'Runtime errors: '+JSON.stringify(errors));check(!failedResources.length,'Failed resources: '+JSON.stringify(failedResources));
    console.log(JSON.stringify({result:'Enterprise browser workflows passed',checks,charts:pixels,screenshots}));
}finally{await command('Target.closeTarget',{targetId:target.targetId});socket.close();}
