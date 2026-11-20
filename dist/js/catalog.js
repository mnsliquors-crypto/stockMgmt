(function ($) {
    'use strict';
    const root = _base_url_ + 'admin/?page=enterprise/catalog';
    let pending = false;
    $('.catalog-form').on('submit', function (event) {
        event.preventDefault(); if (pending) return;
        const form = $(this), action = form.data('action'), data = Object.fromEntries(new FormData(this).entries());
        if (action === 'generate_variants') data.axes = form.find('.matrix-axis').map(function () { return {name: $(this).find('.matrix-axis-name').val(), values: $(this).find('.matrix-axis-values').val().split(',').map(v => v.trim()).filter(Boolean)}; }).get();
        const buttons = form.find('button:enabled'); buttons.prop('disabled', true); pending = true;
        $('#catalog-message').empty();
        $.ajax({url: _base_url_ + 'classes/Catalog.php?f=' + action, method: 'POST', data: data, dataType: 'json'})
            .done(function (r) { if (r.status !== 'success') { showError(r.msg); return; } if(form.data('stay')){location.reload();return;} location.href = action === 'save_item' || action === 'generate_variants' ? root + '&id=' + r.data.id : root + '&view=' + (form.data('view') || 'features') + (action === 'save_usage' ? '&record_type=' + form.data('kind') : ''); })
            .fail(function (xhr) { showError((xhr.responseJSON || {}).msg || 'The request failed. Please try again.'); })
            .always(function () { pending = false; buttons.prop('disabled', false); });
    });
    function showError(text) { $('#catalog-message').empty().append($('<div class="alert alert-danger">').text(text)); $('#catalog-message')[0].scrollIntoView({block:'nearest'}); }
    $('#matrix-add-axis').on('click', function () { if ($('.matrix-axis').length >= 3) return; const row = $('.matrix-axis').first().clone(); row.find('input').val(''); $('#matrix-axes').append(row); });
    $('#matrix-axes').on('click', '.matrix-remove-axis', function () { if ($('.matrix-axis').length > 1) $(this).closest('.matrix-axis').remove(); });
})(jQuery);
