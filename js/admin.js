(function($){
    $(function(){
        var $target = $('#gfcc_target_field');
        var $choices = $('#gfcc_target_choices');

        function loadChoices(fieldId) {
            $choices.prop('disabled', true).empty().append(
                $('<option>').text(GFCC_ADMIN.strings.loading)
            );

            $.post(GFCC_ADMIN.ajaxurl, {
                action: 'gfcc_get_choices',
                nonce: GFCC_ADMIN.nonce,
                form_id: GFCC_ADMIN.formId,
                field_id: fieldId
            }).done(function(resp){
                $choices.empty();
                if (!resp || !resp.success || !resp.data || !resp.data.choices) {
                    $choices.append($('<option>').text(GFCC_ADMIN.strings.error));
                    return;
                }
                resp.data.choices.forEach(function(ch){
                    $choices.append(
                        $('<option>').val(ch.value).text(ch.text)
                    );
                });
            }).fail(function(){
                $choices.empty().append($('<option>').text(GFCC_ADMIN.strings.error));
            }).always(function(){
                $choices.prop('disabled', false);
            });
        }

        $target.on('change', function(){
            var fid = parseInt($(this).val(), 10);
            if (fid) {
                loadChoices(fid);
            } else {
                $choices.empty();
            }
        });
    });
})(jQuery);
