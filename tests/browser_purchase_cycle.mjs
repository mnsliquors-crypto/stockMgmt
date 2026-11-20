import fs from 'node:fs/promises';
const base = process.env.APP_BASE_URL || 'http://127.0.0.1:8081/';
if (new URL(base).port !== '8081') throw new Error('Use the disposable application on port 8081.');
const directory = '/tmp/stockmgmt-purchase-cycle-browser';
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
async function postFirst() {
    await wait('document.readyState==="complete" && !!document.querySelector(".pc-post")','accounting command ready');
    await evaluate('new Promise(resolve=>jQuery(()=>resolve(true)))');
    await evaluate('document.querySelector(".pc-post").click()');
    await wait('!!document.querySelector("#pc-dialog.show")','accounting review');
    check(await evaluate('document.querySelectorAll("#pc-dialog tbody tr").length>=2'),'Balanced entry preview lines');
    await evaluate('document.querySelector("#pc-confirm-post").click()');
    await wait('!document.querySelector("#pc-dialog.show") && document.readyState==="complete"','post reload');
    await evaluate('new Promise(resolve=>jQuery(()=>resolve(true)))');
}
try {
    await send('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
    await login('admin','admin123');await page('admin/?page=transactions/procurement&new=1');
    await evaluate(`(()=>{const v=document.querySelector('#po-vendor');v.value=v.options[1].value;jQuery(v).trigger('change');const i=document.querySelector('.po-item');i.value=i.options[1].value;jQuery(i).trigger('change');document.querySelector('.po-quantity').value='5';document.querySelector('.po-price').value='10.55';document.querySelector('#po-tax-rate').value='13';document.querySelector('#po-notes').value='Purchase cycle browser verification';document.querySelector('#purchase-order-form').requestSubmit();})()`);
    await wait('!!document.querySelector(".po-transition[data-action=submit]")','draft order');
    const order=await evaluate('new URL(location.href).searchParams.get("id")');
    await evaluate('document.querySelector(".po-transition[data-action=submit]").click()');
    await wait('document.querySelector(".procurement-workspace .badge")?.innerText==="Pending"','pending order');
    await login('procurement_browser_checker','ProcurementBrowser123!');await page('admin/?page=transactions/procurement&id='+order);
    await evaluate('document.querySelector(".po-transition[data-action=approve]").click()');await wait('!!document.querySelector(".po-receive-quantity")','approved order');
    check(await evaluate('document.querySelector("#po-receive-form").dataset.separate==="1"&&!document.querySelector("[name=invoice_number]")'),'Receiving is separate from supplier invoice');
    await evaluate('document.querySelector(".po-receive-quantity").value="2";document.querySelector("#po-invoice").value="Delivery Browser";document.querySelector("#po-receive-form").requestSubmit()');
    await wait('!!document.querySelector(".pc-workspace")&&location.href.includes("item_receipts&id=")','item receipt');
    const receipt=await evaluate('new URL(location.href).searchParams.get("id")');
    await capture('receipt-desktop');await fits('Receipt desktop');await postFirst();
    await evaluate(`document.querySelector('a[href*="vendor_bills&new=1"]').click()`);
    await wait('!!document.querySelector("#pc-invoice")','new bill');
    await evaluate('document.querySelector("#pc-invoice").value="BROWSER-BILL-"+Date.now();document.querySelector(".pc-qty").value="1";document.querySelector(".pc-form").requestSubmit()');
    await wait('!!document.querySelector(".pc-command[data-action=record_bill]")','saved bill');
    const bill=await evaluate('new URL(location.href).searchParams.get("id")');
    await capture('bill-draft-desktop');
    await evaluate('document.querySelector(".pc-command[data-action=record_bill]").click()');
    await wait('document.querySelector(".pc-workspace .badge")?.innerText==="Recorded"','recorded bill');await postFirst();
    await wait('!!document.querySelector("#pc-payment-amount")','posted bill payment form');
    await evaluate('document.querySelector("#pc-account").selectedIndex=1;document.querySelector("#pc-payment-amount").value="5.00";document.querySelector(".pc-form[data-action=pay_bill]").requestSubmit()');
    await wait('document.querySelector(".pc-balance")?.innerText.includes("6.92")','partial payment');await postFirst();
    await evaluate('document.querySelector("#pc-account").selectedIndex=1;document.querySelector(".pc-form[data-action=pay_bill]").requestSubmit()');
    await wait('document.querySelector(".pc-balance")?.innerText.includes("0.00")','fully paid');await postFirst();
    await capture('bill-paid-desktop');check(!await evaluate('!!document.querySelector("#pc-payment-amount")'),'Paid bills have no payment form');
    await evaluate('window.print=()=>{window.printCalled=true};document.querySelector(".pc-print").click()');check(await evaluate('window.printCalled'),'Bill print command');
    await send('Emulation.setEmulatedMedia',{media:'print'});await capture('bill-print');
    check(await evaluate('getComputedStyle(document.querySelector(".pc-no-print")).display==="none"'),'Print hides commands');await send('Emulation.setEmulatedMedia',{media:'screen'});
    for(const width of [768,390,320]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<768});
        for(const [name,path]of [['bill','vendor_bills&id='+bill],['receipt','item_receipts&id='+receipt],['order','procurement&id='+order],['bill-draft','vendor_bills&new=1&order_id='+order]]) {
            await page('admin/?page=transactions/'+path);await fits(name+' '+width);await capture(name+'-'+width);
        }
    }
    check(!errors.length,'Browser errors: '+JSON.stringify(errors));
    console.log(JSON.stringify({result:'Purchase cycle browser checks passed',checks,order,receipt,bill,directory}));
} finally {await command('Target.closeTarget',{targetId:target.targetId});socket.close();}
