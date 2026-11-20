(function ($) {
    'use strict';
    $(function () {
        const form=$('#pos-form,#sale-form,#purchase-form,#return-form,#purchase-order-form');
        if (!window._enterprise_enabled_||!form.length) return;
        const isPos=form.is('#pos-form');const isOrder=form.is('#purchase-order-form');
        const isPurchase=form.is('#purchase-form,#purchase-order-form');
        const locked=form.find('[name=id]').prop('disabled')||form.data('editable')===0;
        const endpoint=_base_url_+'classes/Enterprise.php?f=';
        let pendingQuotes=0;
        const recordId=form.find('[name=id]').val();
        const query=isOrder?{order_id:recordId||''}:{id:recordId||''};
        const notify=text=>{if(typeof alert_toast==='function')alert_toast(text,'error');};
        $.getJSON(endpoint+'transaction_options',query).done(function (response) {
            const options=response.data;const packaging=new Map(options.packaging.map(item=>[String(item.item_id),item]));
            const location=$('<select class="form-control form-control-sm" name="location_id" data-no-select2>');
            options.locations.forEach(item=>location.append($('<option>').val(item.id).text(item.name)));
            location.val(String(options.location_id||1)).prop('disabled',!!locked);
            const locationId=form.attr('id')+'-location';location.attr('id',locationId);
            const locationWrap=$('<div class="erp-location-control suite-no-print">').append($('<label>').attr('for',locationId).text('Location'),location);
            form.prepend(locationWrap);
            function quote(row) {
                if (!isPos||locked||row.data('applying-quote')) return;
                const item=row.find('[name="item_id[]"]').val();const quantity=row.find('[name="qty[]"]').val();
                if (!item||!(Number(quantity)>0))return;
                clearTimeout(row.data('quote-timer'));
                const sequence=(row.data('quote-sequence')||0)+1;row.data('quote-sequence',sequence).data('quote-pending',true);
                form.find(':submit').prop('disabled',true);
                row.data('quote-timer',setTimeout(function () {
                    row.data('quote-pending',false);if(!row[0].isConnected)return;
                    pendingQuotes++;form.find(':submit').prop('disabled',true);
                    $.getJSON(endpoint+'checkout_quote',{item_id:item,quantity,unit_code:row.find('[name="unit_code[]"]').val()||'base',location_id:location.val()})
                        .done(function (result) {
                            if (!row[0].isConnected||row.data('quote-sequence')!==sequence)return;
                            row.data('quote-error',false);
                            row.data('applying-quote',true);
                            row.find('[name="price[]"]').val(result.data.price);
                            row.find('.erp-promotion-name').remove();
                            if (result.data.promotion_id) row.find('.item-name').append($('<small class="erp-promotion-name d-block text-success">').text('Promotion #'+result.data.promotion_id));
                            row.find('[name="qty[]"]').trigger('change');row.data('applying-quote',false);
                        }).fail(function(xhr){if(row.data('quote-sequence')===sequence)row.data('quote-error',true);notify((xhr.responseJSON||{}).msg||'Current prices could not be loaded.');})
                        .always(function () {pendingQuotes--;const waiting=form.find('#pos-cart-table tbody tr').toArray().some(entry=>$(entry).data('quote-pending')||$(entry).data('quote-error'));if(!pendingQuotes&&!waiting&&!locked)form.find(':submit').prop('disabled',false);});
                },150));
            }
            function enhanceRows() {
                if(isPos&&!pendingQuotes&&!form.find('#pos-cart-table tbody tr').toArray().some(row=>$(row).data('quote-pending')||$(row).data('quote-error'))&&!locked)form.find(':submit').prop('disabled',false);
                if (isOrder) {
                    if (form.data('editable')!==1)return;
                    form.find('#po-lines tbody tr').each(function () {
                        const row=$(this);const item=packaging.get(String(row.find('.po-item').val()));
                        const cell=row.find('.po-unit');
                        let select=cell.find('.erp-order-unit');
                        if (!select.length) {
                            select=$('<select class="form-control form-control-sm erp-order-unit" data-no-select2>');
                            cell.empty().append(select);
                            select.on('change',function () {
                                const entry=packaging.get(String(row.find('.po-item').val()));if(!entry)return;
                                const price=this.value==='case'?(entry.case_cost??Number(entry.cost)*Number(entry.units_per_case)):entry.cost;
                                row.find('.po-price').val(price).trigger('input');
                            });
                        }
                        const current=select.val()||row.attr('data-unit-code')||'base';
                        const signature=String(item?.item_id||'')+'|'+String(item?.version||0);
                        if (select.attr('data-item-signature')!==signature) {
                            select.empty().append($('<option>').val('base').text(item?.unit||'Base Unit'));
                            if(item&&Number(item.version)>0)select.append($('<option>').val('case').text(item.case_name+' ('+item.units_per_case+')'));
                            select.val(current);if(select.val()===null)select.val('base');select.attr('data-item-signature',signature);
                        }
                    });return;
                }
                const body=form.find('#list tbody,#pos-cart-table tbody');
                body.find('tr').each(function () {
                    const row=$(this);if(row.find('[name="unit_code[]"]').length)return;
                    const itemId=row.find('[name="item_id[]"]').val();if(!itemId)return;
                    const item=packaging.get(String(itemId));
                    const select=$('<select class="form-control form-control-sm erp-line-unit" name="unit_code[]" data-no-select2 aria-label="Item unit">').append($('<option>').val('base').text(item?.unit||'Base Unit'));
                    if(item&&Number(item.version)>0) select.append($('<option>').val('case').text(item.case_name+' ('+item.units_per_case+')'));
                    select.val(row.attr('data-unit-code')||'base').prop('disabled',!!locked);
                    const target=row.find('.item-name,.item').first();(target.length?target:row.find('[name="item_id[]"]').closest('td')).append(select);
                    select.on('change',function () {
                        if(isPos){quote(row);return;}
                        if(!item)return;
                        const price=this.value==='case'?(isPurchase?(item.case_cost??Number(item.cost)*Number(item.units_per_case)):(item.case_price??Number(item.selling_price)*Number(item.units_per_case))):(isPurchase?item.cost:item.selling_price);
                        row.find('[name="price[]"]').val(price).trigger('input');
                    });
                    if(isPos)quote(row);
                });
            }
            enhanceRows();
            const observed=isOrder?document.querySelector('#po-lines tbody'):form.find('#list tbody,#pos-cart-table tbody')[0];
            if(observed)new MutationObserver(enhanceRows).observe(observed,{childList:true,subtree:true});
            if(isPos) {
                form.on('input.enterprise change.enterprise','[name="qty[]"]',function(){quote($(this).closest('tr'));});
                location.on('change',()=>form.find('#pos-cart-table tbody tr').each(function(){quote($(this));}));
                form[0].addEventListener('submit',function(event){if(pendingQuotes||form.find('#pos-cart-table tbody tr').toArray().some(row=>$(row).data('quote-pending')||$(row).data('quote-error'))){event.preventDefault();event.stopImmediatePropagation();notify('Wait for current item prices before completing this sale.');}},true);
            }
        }).fail(()=>notify('Inventory options are unavailable. Refresh the page before changing units or locations.'));
    });
})(jQuery);
