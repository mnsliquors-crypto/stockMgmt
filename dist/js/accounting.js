(function ($) {
    'use strict';
    const root = _base_url_ + 'admin/?page=finance/accounting';
    function message(text, error) {
        $('#accounting-message').empty().append($('<div>').addClass('alert ' + (error ? 'alert-danger' : 'alert-success')).text(text));
    }
    function api(action, data, button) {
        const buttons = $(button).prop('disabled', true);
        return $.ajax({url: _base_url_ + 'classes/Accounting.php?f=' + action, method: 'POST', data: data, dataType: 'json'})
            .always(function () { buttons.prop('disabled', false); });
    }
    function errorText(xhr) { return (xhr.responseJSON && xhr.responseJSON.msg) || 'The operation could not be completed.'; }
    function lineTotals() {
        let debit = 0, credit = 0;
        $('#journal-lines tbody tr').each(function () {
            debit += Math.round((parseFloat($(this).find('.journal-debit').val()) || 0) * 100);
            credit += Math.round((parseFloat($(this).find('.journal-credit').val()) || 0) * 100);
        });
        $('#journal-debit-total').text((debit / 100).toFixed(2));
        $('#journal-credit-total').text((credit / 100).toFixed(2));
    }
    $(function () {
        lineTotals();
        $('#journal-lines').on('input change', 'input', lineTotals);
        $('#add-journal-line').on('click', function () {
            const row = $('#journal-lines tbody tr:first').clone();
            row.find('.select2-container').remove();
            row.find('select').removeClass('select2-hidden-accessible').removeAttr('data-select2-id tabindex aria-hidden');
            row.find('option').removeAttr('data-select2-id');
            row.find('input,select').val('');
            $('#journal-lines tbody').append(row);
            lineTotals();
        });
        $('#journal-lines').on('click', '.remove-line', function () {
            if ($('#journal-lines tbody tr').length > 2) $(this).closest('tr').remove();
            lineTotals();
        });
        $('#journal-form').on('submit', function (event) {
            event.preventDefault();
            const data = $(this).serializeArray();
            $('#journal-lines tbody tr').each(function (index) {
                const row = $(this);
                ['account','description','debit','credit'].forEach(function (key) {
                    data.push({name: 'lines[' + index + '][' + (key === 'account' ? 'account_id' : key) + ']', value: row.find('.journal-' + key).val() || (key === 'debit' || key === 'credit' ? '0' : '')});
                });
            });
            api('save_draft', data, $(this).find(':submit')).done(function (response) { location.href = root + '&id=' + response.data.id; })
                .fail(function (xhr) { message(errorText(xhr), true); });
        });
        $('.accounting-form').on('submit', function (event) {
            event.preventDefault();
            api($(this).data('action'), $(this).serialize(), $(this).find(':submit')).done(function () { location.reload(); })
                .fail(function (xhr) { message(errorText(xhr), true); });
        });
        $('.accounting-action').on('click', function () {
            const action = $(this).data('action');
            api(action, {id: $(this).data('id')}, this).done(function (response) {
                location.href = action === 'discard_draft' ? root : root + '&id=' + response.data.id;
            }).fail(function (xhr) { message(errorText(xhr), true); });
        });
        $('.reason-action').on('click', function () {
            const form = $('#accounting-reason-form');
            const action = $(this).data('action');
            form.find('[name=id]').val($(this).data('id'));
            form.find('[name=action]').val(action);
            form.find('[name=reason]').val('');
            form.find('.reason-error').text('');
            form.find('.reversal-date').toggle(action === 'reverse_journal');
            form.find('[name=entry_date]').prop('required', action === 'reverse_journal');
            $('#accounting-reason-title').text({reverse_journal: 'Reverse Journal', close_period: 'Close Period', reopen_period: 'Reopen Period'}[action]);
            $('#accounting-reason-modal').modal('show');
        });
        $('#accounting-reason-form').on('submit', function (event) {
            event.preventDefault();
            api($(this).find('[name=action]').val(), $(this).serialize(), $(this).find(':submit')).done(function (response) {
                location.href = response.data.id ? root + '&id=' + response.data.id : location.href;
            }).fail(function (xhr) { $('#accounting-reason-form .reason-error').text(errorText(xhr)); });
        });
        $('.preview-source').on('click', function () {
            const id = $(this).data('id');
            api('preview_source', {id: id}, this).done(function (response) {
                const data = response.data;
                $('#source-description').text(data.document.reference_code + ' | ' + data.document.transaction_date);
                $('#source-lines').empty();
                data.lines.forEach(function (line) {
                    $('<tr>').append($('<td>').text(line.account.code + ' - ' + line.account.name), $('<td class="text-right">').text(line.debit), $('<td class="text-right">').text(line.credit)).appendTo('#source-lines');
                });
                $('#source-error').text('');
                $('#post-reviewed-source').data('id', id);
                $('#accounting-preview-modal').modal('show');
            }).fail(function (xhr) { message(errorText(xhr), true); });
        });
        $('#post-reviewed-source').on('click', function () {
            api('post_source', {id: $(this).data('id')}, this).done(function (response) { location.href = root + '&id=' + response.data.id; })
                .fail(function (xhr) { $('#source-error').text(errorText(xhr)); });
        });
        $('#financial-report').on('change', function () { $('#report-from-field').toggle(this.value === 'income_statement'); }).trigger('change');
    });
})(jQuery);
