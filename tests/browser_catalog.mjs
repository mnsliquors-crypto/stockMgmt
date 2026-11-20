import fs from 'node:fs/promises';
const base = process.env.APP_BASE_URL || 'http://127.0.0.1:8081/';
if (new URL(base).port !== '8081') throw new Error('Use the disposable application on port 8081.');
const directory = '/tmp/stockmgmt-catalog-browser';
await fs.mkdir(directory, {recursive:true});
const version = await (await fetch('http://127.0.0.1:9223/json/version')).json();
const socket = new WebSocket(version.webSocketDebuggerUrl);
await new Promise(resolve => socket.addEventListener('open', resolve, {once:true}));
let sequence = 0, checks = 0; const pending = new Map(), errors = [];
socket.addEventListener('message', event => {
    const m = JSON.parse(event.data);
    if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.text);
    if (m.method === 'Page.javascriptDialogOpening') command('Page.handleJavaScriptDialog', {accept:true,promptText:'Browser verification'}, sessionId);
    const p = pending.get(m.id); if (p) { pending.delete(m.id); m.error ? p.reject(new Error(m.error.message)) : p.resolve(m.result); }
});
function command(method, params = {}, sessionId) {
    return new Promise((resolve,reject) => {
        const id = ++sequence, timer = setTimeout(() => {pending.delete(id);reject(new Error('CDP timeout: '+method));},20000);
        pending.set(id,{resolve:r=>{clearTimeout(timer);resolve(r);},reject:e=>{clearTimeout(timer);reject(e);}});
        socket.send(JSON.stringify({id,method,params,sessionId}));
    });
}
const target = await command('Target.createTarget',{url:'about:blank'});
const {sessionId} = await command('Target.attachToTarget',{targetId:target.targetId,flatten:true});
const send = (method,params) => command(method,params,sessionId);
await send('Page.enable'); await send('Runtime.enable');
async function evaluate(expression) {
    const r = await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});
    if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails)); return r.result.value;
}
function check(ok,label) { if(!ok)throw new Error(label);checks++; }
async function wait(expression,label) {
    const deadline=Date.now()+15000;
    while(Date.now()<deadline){if(await evaluate(expression)){checks++;return;}await new Promise(r=>setTimeout(r,80));}
    throw new Error('Wait failed '+label+': '+await evaluate('document.body.innerText.slice(-1800)'));
}
async function page(path) {
    await send('Page.navigate',{url:base+path});
    await wait('document.readyState==="complete" && location.href==='+JSON.stringify(base+path),path);
    await evaluate('new Promise(resolve=>jQuery(()=>resolve(true)))');
    check(!await evaluate('/Fatal error|Uncaught |Warning:/.test(document.body.innerText)'),path+' PHP error');
}
async function login(username,password) {
    await send('Network.clearBrowserCookies');await page('admin/login.php');
    await api('Login','login',{username,password});
}
async function api(controller,action,data) {
    const result=await evaluate(`(async()=>{const r=await fetch(${JSON.stringify(base+'classes/'+controller+'.php?f='+action)},{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:new URLSearchParams(${JSON.stringify(data)})});return {status:r.status,body:await r.json()};})()`);
    check(result.status===200&&result.body.status==='success',action+': '+JSON.stringify(result));return result.body.data;
}
async function capture(name) {
    const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
    await fs.writeFile(directory+'/'+name+'.png',Buffer.from(shot.data,'base64'));
}
async function fits(label) {check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),label+' overflow');}

try {
    await send('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    await login('admin','admin123');
    const parents=[];
    for(const [type,usage] of [['inventory','resale'],['assembly','purchase'],['non_inventory','sale'],['service','resale']]) {
        await page('admin/?page=enterprise/catalog&new=1');
        check(await evaluate('document.querySelector("#cat-item_type").options.length===4 && document.querySelector("#cat-item_usage").options.length===3'),'All type and usage choices');
        const label='Browser '+type+' '+Date.now();
        await evaluate(`document.querySelector('#cat-name').value=${JSON.stringify(label)};document.querySelector('#cat-sku').value='B-'+Date.now();document.querySelector('#cat-item_type').value=${JSON.stringify(type)};document.querySelector('#cat-item_usage').value=${JSON.stringify(usage)};document.querySelector('#cat-cost').value='10';document.querySelector('#cat-selling_price').value='25';document.querySelector('#cat-is-matrix').checked=true;document.querySelector('.catalog-form').requestSubmit()`);
        await wait('document.readyState==="complete" && !!document.querySelector("#matrix-axes")','saved matrix parent');
        const id=await evaluate('new URL(location.href).searchParams.get("id")');parents.push(id);
        await evaluate('new Promise(resolve=>jQuery(()=>resolve(true)))');
        await evaluate('document.querySelector(".matrix-axis-name").value="Color";document.querySelector(".matrix-axis-values").value="Red, Blue";document.querySelector(".catalog-form[data-action=generate_variants]").requestSubmit()');
        await wait('document.readyState==="complete" && document.querySelectorAll(".catalog-workspace tbody tr").length===2','generated '+type+' variants');
        check(await evaluate(`document.querySelector('#cat-item_type').value===${JSON.stringify(type)} && document.querySelector('#cat-item_usage').value===${JSON.stringify(usage)}`),'Parent type and usage retained');
        await capture(type+'-matrix-desktop');
        const child=await evaluate('document.querySelector(".catalog-workspace tbody a").href');
        await page(child.slice(base.length));
        check(await evaluate('document.querySelector("#cat-item_type").disabled && document.querySelector("#cat-item_usage").disabled'),'Variants inherit type and usage');
        const version=await evaluate('Number(document.querySelector("[name=catalog_version]").value)');
        await evaluate('document.querySelector("#cat-selling_price").value="27.50";document.querySelector(".catalog-form").requestSubmit()');
        await wait('document.readyState==="complete" && Number(document.querySelector("[name=catalog_version]")?.value)>'+version,'variant price saved');
        check(await evaluate('document.querySelector("#cat-selling_price").value==="27.5000"'),'Variants have independent pricing');
    }
    for(const view of ['units','bins','class','department']) {
        await page('admin/?page=enterprise/catalog&view='+view);
        await evaluate(`document.querySelector('#master-code').value='BR-'+Date.now();document.querySelector('#master-name').value='Browser ${view} '+Date.now();${view==='bins'?'document.querySelector("#cat-location_id").value="1";':''}document.querySelector('.catalog-form').requestSubmit()`);
        await wait('document.readyState==="complete" && Array.from(document.querySelectorAll("tbody td")).some(t=>t.innerText.startsWith("Browser '+view+' "))','saved '+view);
    }
    await page('admin/?page=enterprise/catalog&view=features');
    check(await evaluate('document.querySelector("#cat-matrix-feature").checked'),'Matrix switch is enabled');
    await evaluate('document.querySelector("#cat-matrix-feature").checked=false;document.querySelector(".catalog-form").requestSubmit()');
    await wait('document.querySelector("#catalog-message").innerText.includes("Deactivate matrix")','feature disable protects active families');
    for(const kind of ['account','unit','bin','customer','vendor','location','class','department']) {
        await page('admin/?page=enterprise/catalog&view=usage&record_type='+kind);
        check(await evaluate('document.querySelectorAll(".catalog-usage-form").length>0 && Array.from(document.querySelectorAll(".catalog-usage-form select")).every(s=>s.options.length===3)'),'Usage choices for '+kind);
    }
    await page('admin/?page=enterprise/catalog&view=usage&record_type=unit');
    const usage=await evaluate('(()=>{const f=Array.from(document.querySelectorAll(".catalog-usage-form")).find(f=>f.closest("tr").innerText.includes("Browser units"));return {id:f.querySelector("[name=record_id]").value,version:Number(f.querySelector("[name=version]").value)};})()');
    await evaluate(`(()=>{const f=document.querySelector('[name=record_id][value="${usage.id}"]').form;f.querySelector('select').value='purchase';f.requestSubmit()})()`);
    await wait(`document.readyState==='complete' && Number(document.querySelector('[name=record_id][value="${usage.id}"]')?.form.querySelector('[name=version]').value)>${usage.version}`,'master usage saved');
    check(await evaluate(`document.querySelector('[name=record_id][value="${usage.id}"]').form.querySelector('select').value==='purchase'`),'Master usage persists');
    await evaluate(`(()=>{const f=document.querySelector('[name=record_id][value="${usage.id}"]').form;f.querySelector('select').value='resale';f.requestSubmit()})()`);
    await wait(`document.readyState==='complete' && Number(document.querySelector('[name=record_id][value="${usage.id}"]')?.form.querySelector('[name=version]').value)>${usage.version+1}`,'master usage restored');
    for(const width of [768,390,320]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<768});
        for(const [name,path]of [['items',''],['item-new','&new=1'],['service-matrix','&id='+parents[3]],['bins','&view=bins'],['classes','&view=class'],['departments','&view=department'],['usage','&view=usage&record_type=unit'],['features','&view=features']]) {
            await page('admin/?page=enterprise/catalog'+path);await fits(name+' '+width);await capture(name+'-'+width);
        }
    }
    check(!errors.length,'Browser errors: '+JSON.stringify(errors));
    console.log(JSON.stringify({result:'Catalog browser checks passed',checks,parents,directory}));
} finally {await command('Target.closeTarget',{targetId:target.targetId});socket.close();}

