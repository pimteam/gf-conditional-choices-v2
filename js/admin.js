(function($){
    $(function(){

        // General UI (toggle postboxes)
        $('.postbox .hndle').on('click', function(){
            $(this).closest('.postbox').toggleClass('closed');
        });
        $('.postbox .handlediv').on('click', function(){
            $(this).closest('.postbox').toggleClass('closed');
        });

        var $form = $('#gfcc_edit_form');
        if (!$form.length) {
            return; // Not on the edit page
        }

        var choiceCache = {};

        // =================================================================
        // TARGET FIELD SELECTION (for new configurations)
        // =================================================================
        $('#gfcc_target_field_selector').on('change', function(){
            var fieldId = $(this).val();
            var $choicesBox = $('#gfcc-choices-box');
            var $choicesList = $('#gfcc-available-choices');

            if (!fieldId) {
                $choicesBox.hide();
                $choicesList.empty().append('<li>' + GFCC_ADMIN.strings.error + '</li>');
                return;
            }

            $choicesBox.show();
            $choicesList.empty().append('<li>' + GFCC_ADMIN.strings.loading + '</li>');

            // Use cache if available
            if (choiceCache[fieldId]) {
                populateAvailableChoices(choiceCache[fieldId]);
                return;
            }

            $.post(GFCC_ADMIN.ajaxurl, {
                action: GFCC_ADMIN.action || 'gfcc_v2_get_choices',
                nonce: GFCC_ADMIN.nonce,
                form_id: GFCC_ADMIN.formId,
                field_id: fieldId
            }).done(function(resp){
                if (resp.success && resp.data.choices) {
                    choiceCache[fieldId] = resp.data.choices;
                    populateAvailableChoices(resp.data.choices);
                } else {
                    $choicesList.empty().append('<li>' + GFCC_ADMIN.strings.error + '</li>');
                }
            }).fail(function(){
                $choicesList.empty().append('<li>' + GFCC_ADMIN.strings.error + '</li>');
            });
        });

        function populateAvailableChoices(choices) {
            var $choicesList = $('#gfcc-available-choices');
            $choicesList.empty();
            if (choices.length === 0) {
                $choicesList.append('<li>No choices found.</li>');
                return;
            }
            choices.forEach(function(ch){
                $choicesList.append(
                    $('<li>').attr('data-value', ch.value).text(ch.text)
                );
            });
            updateChoiceLabels();
        }

        // =================================================================
        // SORTABLE / DRAG & DROP
        // =================================================================
        function initSortable() {
            var $available = $('#gfcc-available-choices');
            var $assigned = $('.gfcc-assigned-choices');

            $available.sortable({
                connectWith: '.gfcc-assigned-choices',
                helper: 'clone',
                placeholder: 'ui-sortable-placeholder',
                stop: function(event, ui) {
                    // If item is dragged back to available, remove any hidden inputs
                    if ($(ui.item).closest('#gfcc-available-choices').length) {
                        $(ui.item).find('input').remove();
                        $(ui.item).find('.gfcc-remove-choice').remove();
                    }
                }
            }).disableSelection();

            $assigned.sortable({
                connectWith: '#gfcc-available-choices, .gfcc-assigned-choices',
                placeholder: 'ui-sortable-placeholder',
                receive: function(event, ui) {
                    var $item = $(ui.item);
                    var value = $item.data('value');
                    var text = $item.text();
                    var groupId = $(this).closest('.gfcc-group').data('group-id');
                    var inputName = 'gfcc_config[groups][' + groupId + '][choices][]';

                    // Add hidden input and remove button
                    $item.append($('<input>').attr({type: 'hidden', name: inputName, value: value}));
                    $item.append($('<a>').attr('href', '#').addClass('gfcc-remove-choice').html('&times;'));
                }
            }).disableSelection();
        }

        // =================================================================
        // DYNAMIC GROUPS AND RULES
        // =================================================================
        var $groupsWrapper = $('.gfcc-groups-wrapper');

        // Add Group
        $('#gfcc_add_group').on('click', function(){
            var groupCount = $groupsWrapper.find('.gfcc-group').length;
            var template = $('#tmpl-gfcc-group').html().replace(/__GROUP_ID__/g, groupCount);
            var $newGroup = $(template);
            $newGroup.find('.gfcc-group-label-text').text('New Condition Group ' + (groupCount + 1));
            $newGroup.find('.gfcc-group-label-input').val('New Condition Group ' + (groupCount + 1));
            $groupsWrapper.append($newGroup);
            initSortable(); // Re-init for the new group's choice list
        });

        // Delete Group
        $groupsWrapper.on('click', '.gfcc-delete-group', function(e){
            e.preventDefault();
            if (confirm(GFCC_ADMIN.strings.confirm_delete_group)) {
                $(this).closest('.gfcc-group').remove();
                // Re-index groups to maintain correct naming
                $groupsWrapper.find('.gfcc-group').each(function(idx){
                    updateElementIndexes($(this), idx);
                });
            }
        });

        // Add Rule
        $groupsWrapper.on('click', '.gfcc-add-rule', function(e){
            e.preventDefault();
            var $group = $(this).closest('.gfcc-group');
            var groupId = $group.data('group-id');
            var ruleCount = $group.find('.gfcc-rule').length;
            var template = $('#tmpl-gfcc-rule').html()
                .replace(/__GROUP_ID__/g, groupId)
                .replace(/__RULE_ID__/g, ruleCount);
            $group.find('.gfcc-rules-wrapper').append(template);
        });

        // Delete Rule
        $groupsWrapper.on('click', '.gfcc-delete-rule', function(e){
            e.preventDefault();
            var $rule = $(this).closest('.gfcc-rule');
            var $rulesWrapper = $rule.parent();
            if ($rulesWrapper.find('.gfcc-rule').length > 1) {
                $rule.remove();
            } else {
                // If it's the last rule, just clear its values
                $rule.find('select').val('');
                $rule.find('input').val('');
            }
        });

        // Remove assigned choice
        $groupsWrapper.on('click', '.gfcc-remove-choice', function(e){
            e.preventDefault();
            $(this).closest('li').remove();
        });

        // Edit group label
        $groupsWrapper.on('click', '.hndle', function(e){
            if ($(e.target).is('.gfcc-delete-group')) return;
            var $hndle = $(this);
            var $text = $hndle.find('.gfcc-group-label-text');
            var $input = $hndle.find('.gfcc-group-label-input');
            $text.hide();
            $input.show().focus().select();
        });
        $groupsWrapper.on('blur focusout', '.gfcc-group-label-input', function(){
            var $input = $(this);
            var $text = $input.siblings('.gfcc-group-label-text');
            var newLabel = $input.val() || 'Condition Group';
            $text.text(newLabel);
            $input.hide();
            $text.show();
        });
         $groupsWrapper.on('keydown', '.gfcc-group-label-input', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                $(this).blur();
            }
        });


        // =================================================================
        // HELPERS
        // =================================================================

        /**
         * Recursively update indexes of form elements when a group is deleted.
         */
        function updateElementIndexes($element, newIndex) {
            $element.attr('data-group-id', newIndex);
            $element.find('[name]').each(function(){
                var name = $(this).attr('name');
                if (name) {
                    $(this).attr('name', name.replace(/\[groups\]\[\d+\]/, '[groups][' + newIndex + ']'));
                }
            });
        }

        /**
         * When choices are loaded, update the text of assigned choices which only store value.
         */
        function updateChoiceLabels() {
            var choiceMap = {};
            $('#gfcc-available-choices li').each(function(){
                choiceMap[$(this).data('value')] = $(this).text();
            });

            $('.gfcc-assigned-choices li').each(function(){
                var $li = $(this);
                var value = $li.data('value');
                if (choiceMap[value]) {
                    // Update text but keep hidden input and remove button
                    $li.contents().filter(function() {
                        return this.nodeType === 3; // Node.TEXT_NODE
                    }).first().replaceWith(choiceMap[value]);
                }
            });
        }


        // =================================================================
        // INITIALIZATION
        // =================================================================
        function init() {
            // Trigger change on page load if a target is already selected (edit mode)
            if ($('#gfcc_target_field_selector').val()) {
                $('#gfcc_target_field_selector').trigger('change');
            }
            initSortable();
            $('.gfcc-groups-wrapper .hndle').first().click(); // Open first group by default
        }

        init();
    });
})(jQuery);