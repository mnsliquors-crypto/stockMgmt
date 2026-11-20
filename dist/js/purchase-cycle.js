(function ($) {
    'use strict';
    let pending = false;
    const endpoint = _base_url_ + 'classes/PurchaseCycle.php?f=';
    function error(xhr) {
        const text = (xhr.responseJSON || {}).msg || 'The request failed. Please try again.';
        $('#pc-message').empty().append($('<div class="alert alert-danger">').text(text));
        if ($('#pc-dialog.show').length) $('#pc-dialog .modal-body').append($('<div class="alert alert-danger" role="alert">').text(text));
        $('#pc-message')[0].scrollIntoView({block: 'nearest'});
    }
    function send(url, data, done) {
        if (pending) return;
        pending = true;
        $('#pc-message').empty();
        const buttons = $('.pc-workspace button:enabled,#pc-dialog button:enabled');
        buttons.prop('disabled', true);
        $.ajax({url: url, method: 'POST', data: data, dataType: 'json'})
            .done(function (response) { if (response.status === 'success') done(response.data); else error({responseJSON: response}); })
            .fail(error).always(function () { pending = false; buttons.prop('disabled', false); });
    }
    $('.pc-form').on('submit', function (event) {
        event.preventDefault();
        const form = $(this), action = form.data('action'), data = Object.fromEntries(new FormData(this).entries());
        if (action === 'reverse_receipt' && !window.confirm('Reverse this unbilled receipt and its stock movement?')) return;
        if (form.find('.pc-line').length) {
            data.lines = form.find('.pc-line').map(function () {
                return {[this.dataset.field]: this.dataset.id, quantity: $(this).find('.pc-qty').val()};
            }).get();
        }
        send(endpoint + action, data, function (result) {
            if (form.data('target')) location.href = _base_url_ + 'admin/?page=transactions/' + form.data('target') + '&id=' + result.id;
            else location.reload();
        });
    });
    $('.pc-command').on('click', function () {
        const action = this.dataset.action, data = {id: this.dataset.id, version: this.dataset.version};
        if (action === 'cancel_bill') { data.reason = window.prompt('Reason for cancelling this draft'); if (!data.reason) return; }
        else if (!window.confirm('Record the saved bill? It cannot be edited afterwards.')) return;
        send(endpoint + action, data, function () { location.reload(); });
    });
    $('.pc-fill').on('click', function () { $('.pc-qty').each(function () { this.value = this.max; }); });
    $('.pc-print').on('click', function () { window.print(); });
    $('.pc-post').on('click', function () {
        const id = this.dataset.id;
        send(_base_url_ + 'classes/Accounting.php?f=preview_source', {id: id}, function (data) {
            const body = $('#pc-dialog .modal-body').empty();
            $('<p>').text(data.document.reference_code + ' | ' + data.document.transaction_date).appendTo(body);
            const table = $('<table class="table table-sm"><thead><tr><th>Account</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr></thead><tbody></tbody></table>').appendTo(body);
            data.lines.forEach(function (l) { $('<tr>').append($('<td>').text(l.account.code + ' - ' + l.account.name), $('<td class="text-right">').text(l.debit), $('<td class="text-right">').text(l.credit)).appendTo(table.find('tbody')); });
            $('#pc-confirm-post').data('id', id); $('#pc-dialog').modal('show');
        });
    });
    $('#pc-confirm-post').on('click', function () {
        send(_base_url_ + 'classes/Accounting.php?f=post_source', {id: $(this).data('id')}, function () { location.reload(); });
    });
})(jQuery);
