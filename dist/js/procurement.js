(function ($) {
    'use strict';
    var root = $('.procurement-workspace');
    if (!root.length) return;
    var endpoint = _base_url_ + 'classes/Procurement.php?f=';
    var orderUrl = _base_url_ + 'admin/?page=transactions/procurement';
    var form = $('#purchase-order-form');
    var editable = form.data('editable') === 1;
    var pending = false;
    var message = $('#procurement-message');
    var actionLabels = {reject: 'Reject Order', cancel: 'Cancel Order', close: 'Close Remaining Quantity'};

    function submit(action, data, success, errorTarget) {
        if (pending) return;
        pending = true;
        var buttons = root.find('button:enabled').add($('#po-reason-form button:enabled'));
        buttons.prop('disabled', true);
        (errorTarget || message).empty();
        $.ajax({url: endpoint + action, method: 'POST', data: data, dataType: 'json'})
            .done(function (response) {
                if (response.status === 'success') success(response.data);
                else showError(response.msg || 'The operation could not be completed.', errorTarget);
            })
            .fail(function (xhr) { showError((xhr.responseJSON || {}).msg || 'The request failed. Please try again.', errorTarget); })
            .always(function () { pending = false; buttons.prop('disabled', false); });
    }
    function showError(text, target) {
        if (target) target.text(text);
        else { message.empty().append($('<div class="alert alert-danger">').text(text)); message[0].scrollIntoView({block: 'nearest'}); }
    }
    function selectItems(scope) {
        if ($.fn.select2) scope.find('.po-item').select2({width: '100%', dropdownAutoWidth: true});
    }
    function totals() {
        if (!editable) return;
        var subtotal = 0;
        $('#po-lines tbody tr').each(function () {
            var row = $(this);
            var amount = Math.round((Number(row.find('.po-quantity').val()) || 0) * (Number(row.find('.po-price').val()) || 0) * 100 + 0.000001) / 100;
            row.find('.po-line-total').text(amount.toFixed(2));
            subtotal += amount;
        });
        var tax = Math.round(subtotal * (Number($('#po-tax-rate').val()) || 0) + 0.000001) / 100;
        $('#po-subtotal').text(subtotal.toFixed(2));
        $('#po-tax').text(tax.toFixed(2));
        $('#po-total').text((subtotal + tax).toFixed(2));
    }
    if (!editable) form.find('fieldset :input').prop('disabled', true);
    selectItems(form);
    if ($.fn.select2) $('#po-vendor,#po-replenish-vendor').select2({width: '100%'});
    $('#po-add-line').on('click', function () {
        var first = $('#po-lines tbody tr').first();
        var row = first.clone();
        row.find('.select2-container').remove();
        row.find('.po-item').removeClass('select2-hidden-accessible').removeAttr('data-select2-id tabindex aria-hidden').empty().append(first.find('.po-item option').clone().removeAttr('data-select2-id')).val('');
        row.find('.po-quantity').val('1'); row.find('.po-price').val('');
        row.find('.po-unit').empty(); row.find('.po-line-total').text('0.00');
        $('#po-lines tbody').append(row); selectItems(row); totals();
    });
    root.on('click', '.po-remove-line', function () {
        if ($('#po-lines tbody tr').length === 1) { showError('Keep at least one item on the order.'); return; }
        $(this).closest('tr').remove(); totals();
    });
    root.on('change', '.po-item', function () {
        if (!editable) return;
        var row = $(this).closest('tr'); var option = $(this).find(':selected');
        row.find('.po-price').val(option.data('cost') || '0');
        row.find('.po-unit').text(option.data('unit') || ''); totals();
    });
    root.on('input', '.po-quantity,.po-price,#po-tax-rate', totals);
    form.on('submit', function (event) {
        event.preventDefault();
        if (!editable) return;
        var data = Object.fromEntries(new FormData(this).entries());
        data.lines = $('#po-lines tbody tr').map(function () {
            return {item_id: $(this).find('.po-item').val(), quantity: $(this).find('.po-quantity').val(), unit_price: $(this).find('.po-price').val(),unit_code:$(this).find('.erp-order-unit').val()||'base'};
        }).get();
        submit('save_order', data, function (result) { location.href = orderUrl + '&id=' + result.id; });
    });
    function transition(action, reason, errorTarget) {
        var data = {id: form.find('[name=id]').val(), version: form.find('[name=version]').val(), reason: reason || ''};
        submit(action, data, function () { location.reload(); }, errorTarget);
    }
    $('.po-transition').on('click', function () {
        if (form.find(':input').filter(function () { return this.dataset.dirty === '1'; }).length) { showError('Save the draft before submitting it.'); return; }
        transition($(this).data('action'));
    });
    form.on('input change', ':input:not(:button)', function () { this.dataset.dirty = '1'; });
    $('.po-reason').on('click', function () {
        var action = $(this).data('action');
        $('#po-reason-title').text(actionLabels[action]);
        $('#po-reason-form [name=action]').val(action); $('#po-reason').val(''); $('.po-reason-error').empty();
        $('#po-reason-modal').modal('show');
    });
    $('#po-reason-modal').on('shown.bs.modal', function () { $('#po-reason').trigger('focus'); });
    $('#po-reason-form').on('submit', function (event) {
        event.preventDefault(); transition($(this).find('[name=action]').val(), $('#po-reason').val(), $('.po-reason-error'));
    });
    $('#po-receive-form').on('submit', function (event) {
        event.preventDefault();
        var data = Object.fromEntries(new FormData(this).entries());
        data.lines = $('#po-receiving-lines tbody tr').map(function () { return {order_line_id: $(this).data('line-id'), quantity: $(this).find('.po-receive-quantity').val()}; }).get();
        submit('receive', data, function (result) { location.href = orderUrl + '&id=' + result.id; });
    });
    $('#po-fill-outstanding').on('click', function () { $('.po-receive-quantity').each(function () { $(this).val($(this).attr('max')); }); });
    $('#po-policy-form').on('submit', function (event) { event.preventDefault(); submit('save_policy', $(this).serialize(), function () { location.reload(); }); });
    $('#po-reassign-form').on('submit', function (event) { event.preventDefault(); submit('reassign', $(this).serialize(), function () { location.reload(); }); });
    $('#po-select-all').on('change', function () { $('.po-replenish-check:enabled').prop('checked', this.checked); });
    $('#po-replenishment-form').on('submit', function (event) {
        event.preventDefault(); submit('replenish', $(this).serialize(), function (result) { location.href = orderUrl + '&id=' + result.id; });
    });
    $('#print-purchase-order').on('click', function () { window.print(); });
    totals();
})(jQuery);
