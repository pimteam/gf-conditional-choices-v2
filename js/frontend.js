(function ($) {
  'use strict';

  // Cache for field values to avoid redundant DOM lookups
  var valueCache = {};

  function getFieldValue(formId, fieldId) {
    var cacheKey = formId + '_' + fieldId;
    // For now, we don't cache values as they can change.
    // Caching could be implemented with a mechanism to invalidate on change.

    var $root = $('#gform_' + formId);
    var name = 'input_' + fieldId;

    // Radio buttons
    var $radio = $root.find('input[name="' + name + '"]:checked');
    if ($radio.length) {
      return $radio.val() || '';
    }

    // Checkboxes - returns an array of values
    var $checkboxes = $root.find('input[name^="' + name + '_"]:checked');
    if ($checkboxes.length) {
        var values = [];
        $checkboxes.each(function() {
            values.push($(this).val());
        });
        return values;
    }

    // Other input types (text, select, textarea, hidden)
    var $el = $root.find('#input_' + formId + '_' + fieldId);
    if ($el.length) {
      return $el.val() || '';
    }
    
    return '';
  }

  function applyChoices(formId, fieldId, choices, originalChoices) {
    var $root = $('#gform_' + formId);
    var $fieldWrapper = $root.find('#field_' + formId + '_' + fieldId);

    // Determine which choices to apply (if null, revert to original)
    var choicesToApply = choices === null ? originalChoices : choices;
    var allowedValues = (choicesToApply || []).map(function(ch) { return String(ch.value); });

    // Handle <select> dropdowns
    var $select = $fieldWrapper.find('select');
    if ($select.length) {
      var currentValue = $select.val();
      $select.empty();

      (choicesToApply || []).forEach(function (ch) {
        $select.append($('<option>').attr('value', ch.value).text(ch.text));
      });

      // Restore selection if possible
      if (currentValue && allowedValues.indexOf(String(currentValue)) > -1) {
        $select.val(currentValue);
      } else {
        $select.prop('selectedIndex', 0);
      }
      
      // Trigger change for GF's own logic and other plugins
      $select.data('gfcc-internal-change', true);
      $select.trigger('change');
      $select.removeData('gfcc-internal-change');

      if (window.gform) {
        $select.trigger('chosen:updated');
      }
      return;
    }

    // Handle radio and checkbox lists
    var $inputs = $fieldWrapper.find('.gfield_radio input, .gfield_checkbox input');
    if ($inputs.length) {
      $inputs.each(function () {
        var $input = $(this);
        var val = String($input.val());
        var $choiceWrapper = $input.closest('.gchoice');
        
        if (allowedValues.indexOf(val) > -1) {
          $choiceWrapper.show();
        } else {
          if ($input.is(':checked')) {
            $input.data('gfcc-internal-change', true); // Set flag
            $input.prop('checked', false).trigger('change');
            $input.removeData('gfcc-internal-change'); // Clean up flag
          }
          $choiceWrapper.hide();
        }
      });
    }
  }

  function evaluateRule(formId, rule) {
    var sourceValue = getFieldValue(formId, rule.fieldId);
    var ruleValue = rule.value;

    // For operators that work on arrays (checkboxes)
    if (Array.isArray(sourceValue)) {
        switch (rule.operator) {
            case 'is': // Check if any of the selected checkbox values match
                return sourceValue.indexOf(ruleValue) > -1;
            case 'isnot': // Check if none of the selected checkbox values match
                return sourceValue.indexOf(ruleValue) === -1;
            case 'contains': // Check if a specific choice is checked
                 return sourceValue.indexOf(ruleValue) > -1;
            default:
                return false; // Other operators are not well-defined for arrays
        }
    }

    // For single-value fields
    var numSource = parseFloat(sourceValue);
    var numRule = parseFloat(ruleValue);

    switch (rule.operator) {
      case 'is':
        return sourceValue === ruleValue;
      case 'isnot':
        return sourceValue !== ruleValue;
      case '>':
        return !isNaN(numSource) && !isNaN(numRule) && numSource > numRule;
      case '<':
        return !isNaN(numSource) && !isNaN(numRule) && numSource < numRule;
      case 'contains':
        return sourceValue.indexOf(ruleValue) > -1;
      case 'starts_with':
        return sourceValue.startsWith(ruleValue);
      case 'ends_with':
        return sourceValue.endsWith(ruleValue);
      default:
        return false;
    }
  }

  function updateTarget(formId, targetId, targetConfig) {
    var matchedGroup = null;

    // Find the first group that matches its rules
    (targetConfig.groups || []).some(function(group) {
      if (!group.enabled) return false;

      var ruleResults = (group.rules || []).map(function(rule) {
        return evaluateRule(formId, rule);
      });

      var isMatch = false;
      if (ruleResults.length > 0) {
          if (group.logicType === 'any') {
            isMatch = ruleResults.some(function(res) { return res; });
          } else { // 'all'
            isMatch = ruleResults.every(function(res) { return res; });
          }
      }

      if (isMatch) {
        matchedGroup = group;
        return true; // Stop searching
      }
      return false;
    });

    var choicesToApply = null;
    if (matchedGroup) {
      var allowedValues = matchedGroup.choices || [];
      choicesToApply = targetConfig.originalChoices.filter(function(ch) {
        return allowedValues.indexOf(String(ch.value)) > -1;
      });
    }
    
    applyChoices(formId, targetId, choicesToApply, targetConfig.originalChoices);
  }

  function runAllLogic(formId, formConfig) {
    Object.keys(formConfig.targets || {}).forEach(function(targetId) {
      updateTarget(formId, targetId, formConfig.targets[targetId]);
    });
  }

  function bindForm(formId, formConfig) {
    var $root = $('#gform_' + formId);
    if (!$root.length || $root.data('gfcc-bound')) {
      return;
    }

    var sourceFields = new Set();
    Object.keys(formConfig.targets || {}).forEach(function(targetId) {
      (formConfig.targets[targetId].groups || []).forEach(function(group) {
        (group.rules || []).forEach(function(rule) {
          sourceFields.add(rule.fieldId);
        });
      });
    });

    var handler = function(e) {
      // If the change was triggered by our own script, ignore it to prevent loops.
      if ($(e.target).data('gfcc-internal-change')) {
        return;
      }
      runAllLogic(formId, formConfig);
    };

    sourceFields.forEach(function(fieldId) {
      // Standard inputs
      $root.on('change.gfcc keyup.gfcc', '#input_' + formId + '_' + fieldId, handler);
      // Radio and Checkbox fields
      $root.on('change.gfcc', 'input[name^="input_' + fieldId + '"]', handler);
    });

    // Initial run
    runAllLogic(formId, formConfig);

    $root.data('gfcc-bound', true);
  }

  // GF uses this hook for multi-page forms and AJAX-enabled forms
  $(document).on('gform_post_render', function (e, formId) {
    if (window.GFCC_FORMS && window.GFCC_FORMS[formId]) {
      bindForm(formId, window.GFCC_FORMS[formId]);
    }
  });

  // Fallback for forms that are already on the page when the script loads
  $(function () {
    if (window.GFCC_FORMS) {
      Object.keys(window.GFCC_FORMS).forEach(function (formId) {
        bindForm(formId, window.GFCC_FORMS[formId]);
      });
    }
  });

})(jQuery);