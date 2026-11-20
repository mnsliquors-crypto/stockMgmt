(function ($) {
    'use strict';
    $(function () {
        const root=$('.suite-workspace');
        if (!root.length) return;
        root.find('select:not([multiple])').each(function(){const select=$(this),first=select.find('option').first();if(first.val()!==''||!first.text().trim()||select.is('[data-no-select2]'))return;if(select.hasClass('select2-hidden-accessible'))select.select2('destroy');select.attr('data-placeholder',first.text());});
        if(typeof init_select2==='function')init_select2(root);
        const endpoint=_base_url_+'classes/Enterprise.php?f=';
        let busy=false;
        function error(text,target) {
            if (target) { target.text(text);return; }
            $('#suite-message').empty().append($('<div class="alert alert-danger">').text(text));
            document.querySelector('#suite-message').scrollIntoView({block:'nearest'});
        }
        function post(action,data,success,target) {
            if (busy) return;
            busy=true;const buttons=root.find('button:enabled');buttons.prop('disabled',true);
            $.ajax({url:endpoint+action,method:'POST',data,dataType:'json'}).done(function (response) {
                if (response.status==='success') success(response.data);else error(response.msg||'The operation failed.',target);
            }).fail(xhr=>error((xhr.responseJSON||{}).msg||'The request failed.',target)).always(()=>{busy=false;buttons.prop('disabled',false);});
        }
        root.find('.suite-form').on('submit',function (event) {
            event.preventDefault();const form=$(this);let data=form.serialize();
            if (form.is('.suite-lines-form,.suite-promotion-form')) {
                data=Object.fromEntries(new FormData(this).entries());
                const lines=form.find('.suite-edit-lines tbody tr').map(function () {
                    const row=$(this);
                    return form.hasClass('suite-promotion-form')?{item_id:row.find('.line-item').val(),discount_value:row.find('.line-discount').val()}:
                        {item_id:row.find('.line-item').val(),unit_code:row.find('.line-unit').val(),quantity:row.find('.line-quantity').val()};
                }).get();
                if (form.hasClass('suite-promotion-form')) {data.items=lines;delete data['locations[]'];data.locations=form.find('[name="locations[]"]').val()||[];}
                else data.lines=lines;
            }
            post(form.data('action'),data,()=>{location.href=form.data('redirect')||location.href;});
        });
        root.on('click','.suite-add-line',function () {
            const body=$(this).closest('form').find('.suite-edit-lines tbody');const row=body.children().first().clone();
            row.find('.select2-container').remove();row.find('select').removeClass('select2-hidden-accessible').removeAttr('data-select2-id tabindex aria-hidden').find('option').removeAttr('data-select2-id');
            row.find('input,select').val('');row.find('.line-quantity').val('1');row.find('.line-unit').val('base');body.append(row);
        });
        root.on('click','.suite-remove-line',function () {const row=$(this).closest('tr');if (row.siblings().length) row.remove();else error('Keep at least one line.');});
        root.on('click','.suite-print',()=>window.print());
        $('#suite-share-copy').on('click',async function(){try{await navigator.clipboard.writeText($('#suite-share-message').val());$('#suite-share-error').text('Copied.');}catch(error){$('#suite-share-error').text('Clipboard unavailable. Select the message to copy it.');}});
        $('#suite-share-open').on('click',function(){const phone=$('#suite-share-phone').val().replace(/[\s()+-]/g,'');const message=$('#suite-share-message').val();if(!/^[1-9][0-9]{10,14}$/.test(phone)||!message.trim()||message.length>8000){$('#suite-share-error').text('Enter a phone number including country code and a message of at most 8,000 characters.');return;}window.open('https://wa.me/'+phone+'?text='+encodeURIComponent(message),'_blank','noopener,noreferrer');});
        $('#suite-report-search').on('input',function () {const query=this.value.toLowerCase();$('.suite-report-link').each(function () {this.hidden=!this.textContent.toLowerCase().includes(query);});$('.suite-report-group').each(function () {this.hidden=!$(this).find('.suite-report-link').toArray().some(link=>!link.hidden);});});
        const packaging=document.querySelector('#suite-packaging-data');
        if (packaging) {
            const rows=JSON.parse(packaging.textContent);
            $('#packaging-item').on('change',function () {
                const row=rows.find(item=>String(item.item_id)===String(this.value));if (!row) return;
                const form=$(this).closest('form');
                ['version','case_name','units_per_case','barcode','case_barcode','case_cost','case_price'].forEach(key=>form.find('[name="'+key+'"]').val(row[key]??''));
            }).trigger('change');
        }
        $('#promotion-all-locations').on('change',function () {$('#promotion-locations').prop('disabled',this.checked);}).trigger('change');
        $('#credit-source').on('change',function(){const selected=$(this).find(':selected');$('#credit-invoice option[value!=""]').each(function(){this.disabled=this.dataset.party!==String(selected.data('party'))||this.dataset.type!==String(selected.data('type'));});$('#credit-invoice').val('').trigger('change');});
        if (document.querySelector('#suite-calendar')&&typeof FullCalendar!=='undefined') {
            const calendar=new FullCalendar.Calendar(document.querySelector('#suite-calendar'),{
                initialView:'dayGridMonth',height:'auto',headerToolbar:{left:'prev,next today',center:'title',right:'dayGridMonth,listMonth'},
                events:function (range,success,failure) {
                    $.getJSON(endpoint+'activities',{from:range.startStr.slice(0,10),to:range.endStr.slice(0,10)}).done(response=>success(response.data.map(row=>({
                        id:row.id,title:row.title,start:row.starts_at.replace(' ','T'),end:row.ends_at?row.ends_at.replace(' ','T'):null,
                        url:root.data('url')+'&id='+row.id,color:row.status==='completed'?'#78878a':(row.kind==='event'?'#567f9a':(row.kind==='call'?'#926f8b':'#087f75'))
                    })))).fail(failure);
                }
            });calendar.render();
        }
        $('#suite-assistant-form').on('submit',function (event) {
            event.preventDefault();post('interpret',$(this).serialize(),result=>{
                const list=$('#suite-assistant-fields').empty();
                const fields={Intent:result.intent,Quantity:result.quantity??'Not matched',Unit:result.unit??'Not matched',Amount:result.amount??'Not matched',
                    Items:result.items.map(item=>item.name).join(', ')||'Not matched',Parties:result.parties.map(party=>party.display_name).join(', ')||'Not matched'};
                Object.entries(fields).forEach(([key,value])=>list.append($('<dt class="col-sm-3">').text(key),$('<dd class="col-sm-9">').text(value)));
                $('#suite-assistant-route').attr('href',_base_url_+'admin/?page='+result.route);$('#suite-assistant-result').prop('hidden',false);
            });
        });
        if (String(root.data('can-write'))==='0') root.find('.suite-form :input').prop('disabled',true);

        const dashboardElement=document.querySelector('#suite-dashboard-data');
        if (!dashboardElement) return;
        const dashboard=JSON.parse(dashboardElement.textContent);const charts=[];
        const money=value=>Number(value||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
        $('#dashboard-account').on('change',function () {
            const account=dashboard.accounts.find(row=>String(row.id)===String(this.value));
            ['opening','in','out','closing'].forEach(key=>$('#dashboard-account-'+key).text(account?money(account[key==='in'?'amount_in':(key==='out'?'amount_out':key)]):'0.00'));
        }).trigger('change');
        function chart(id,type,labels,datasets) {
            if (typeof Chart==='undefined') return;
            const empty={afterDraw(instance){if(type!=='doughnut'||datasets.some(set=>set.data.some(value=>Number(value)!==0)))return;const context=instance.chart.ctx;context.save();context.fillStyle='#76838b';context.font='13px Arial';context.textAlign='center';context.fillText('No payments in this period',instance.chart.width/2,instance.chart.height/2);context.restore();}};
            charts.push(new Chart(document.getElementById(id),{type,data:{labels,datasets},plugins:[empty],options:{responsive:true,maintainAspectRatio:false,animation:{duration:200},legend:{display:true,position:'bottom',labels:{boxWidth:10,fontSize:11}},scales:type==='doughnut'?{}:{yAxes:[{ticks:{beginAtZero:true}}],xAxes:[{gridLines:{display:false},ticks:{maxTicksLimit:8}}]}}}));
        }
        const colors=['#087f75','#7a8f93','#ab6170','#567f9a','#89966b','#9a86a2'];
        chart('suite-trend-chart','line',dashboard.trend.map(row=>row.day),['sales','purchases'].map((key,index)=>({label:key==='sales'?'Sales':'Purchases',data:dashboard.trend.map(row=>row[key]),borderColor:colors[index],backgroundColor:'transparent',fill:false,lineTension:0.2,pointRadius:2})));
        chart('suite-monthly-chart','bar',dashboard.monthly.map(row=>row.month),['sales','purchases','expenses'].map((key,index)=>({label:key.charAt(0).toUpperCase()+key.slice(1),data:dashboard.monthly.map(row=>row[key]),backgroundColor:colors[index]})));
        chart('suite-payment-chart','doughnut',dashboard.payments.map(row=>row.name),[{label:'Customer Payments',data:dashboard.payments.map(row=>row.amount),backgroundColor:colors}]);
        chart('suite-category-chart','bar',dashboard.categories.map(row=>row.name),[{label:'Revenue',data:dashboard.categories.map(row=>row.revenue),backgroundColor:colors[0]}]);
        chart('suite-hourly-chart','bar',dashboard.hourly.map(row=>String(row.hour).padStart(2,'0')+':00'),[{label:'POS Sales',data:dashboard.hourly.map(row=>row.amount),backgroundColor:colors[3]}]);
        chart('suite-profit-chart','line',dashboard.profit_trend?dashboard.profit_trend.map(row=>row.Date):[],[{label:'Gross Profit',data:(dashboard.profit_trend||[]).map(row=>Number(row.Revenue)-Number(row.COGS)),borderColor:colors[0],backgroundColor:'transparent',fill:false}]);
        chart('suite-expense-chart','bar',dashboard.trend.map(row=>row.day),[{label:'Expenses',data:dashboard.trend.map(row=>row.expenses),backgroundColor:colors[2]}]);
        function themeCharts() {
            const style=getComputedStyle(root[0]),ink=style.getPropertyValue('--suite-muted').trim(),line=style.getPropertyValue('--suite-line').trim();
            charts.forEach(item=>{item.options.legend.labels.fontColor=ink;['xAxes','yAxes'].forEach(axis=>(item.options.scales[axis]||[]).forEach(scale=>{scale.ticks.fontColor=ink;scale.gridLines.color=line;}));item.update(0);});
        }
        themeCharts();new MutationObserver(themeCharts).observe(root[0],{attributes:true,attributeFilter:['data-theme']});
        $('#suite-dashboard-refresh').on('click',()=>location.reload());
        let timer=null;$('#suite-auto-refresh').on('change',function () {if (timer)clearInterval(timer);if(this.checked)timer=setInterval(()=>{if(!document.hidden&&!$('.modal.show').length)location.reload();},30000);});
        function preferencesData() {const form=document.querySelector('#suite-preferences-form');return {version:form.elements.version.value,theme:form.elements.theme.value,widgets:$(form).find('[name="widgets[]"]:checked').map(function(){return this.value;}).get()};}
        function savePreferences(data) {post('save_preferences',data,result=>{
            $('#suite-preferences-form [name=version]').val(result.version);root.attr('data-theme',result.settings.theme);
            root.find('[data-widget]').each(function(){this.hidden=result.settings.widgets.includes(this.dataset.widget);});
            $('#suite-preferences-modal').modal('hide');charts.forEach(item=>item.resize());
        },$('.suite-preferences-error'));}
        $('#suite-preferences-form').on('submit',function(event){event.preventDefault();savePreferences(preferencesData());});
        $('#suite-theme-toggle').on('click',function(){const data=preferencesData();data.theme=data.theme==='dark'?'light':'dark';$('#suite-preferences-theme').val(data.theme).trigger('change');savePreferences(data);});
    });
})(jQuery);
