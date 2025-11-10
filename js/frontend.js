(function ($) {
  'use strict';

  function getFieldValue(formId, fieldId) {
    var $root = $('#gform_' + formId);

    // text/hidden/textarea/select
    var $el = $root.find('#input_' + formId + '_' + fieldId);
    if ($el.length) {
      if ($el.is('select')) {
        var v = $el.val();
        if (Array.isArray(v)) v = v[0] || '';
        return (v || '').toString();
      }
      if ($el.is('input, textarea')) {
        return ($el.val() || '').toString();
      }
    }

    // radio - ПОПРАВЕН SELECTOR
    var $rad = $root.find('input[name="input_' + formId + '_' + fieldId + '"][type=radio]:checked');
    if ($rad.length) return ($rad.val() || '').toString();

    // checkbox не поддържаме в M1
    return '';
  }

  function applyChoices(formId, fieldId, choices) {
    var $root = $('#gform_' + formId);

    console.log('[GFCC] applyChoices for field ' + fieldId + ', choices:', choices);

    // select
    var $sel = $root.find('#input_' + formId + '_' + fieldId);
    console.log('[GFCC] Looking for select: #input_' + formId + '_' + fieldId, 'found:', $sel.length, 'is select:', $sel.is('select'));

    if ($sel.length && $sel.is('select')) {
      var current = $sel.val();
      console.log('[GFCC] SELECT field - current value:', current);
      $sel.empty();
      (choices || []).forEach(function (ch) {
        $sel.append($('<option/>').attr('value', ch.value).text(ch.text));
      });
      if (current && (choices || []).some(function (ch) { return String(ch.value) === String(current); })) {
        $sel.val(current);
      } else {
        $sel.prop('selectedIndex', 0);
      }
      $sel.trigger('change').trigger('chosen:updated'); // Enhanced UI
      console.log('[GFCC] SELECT updated, new options count:', $sel.find('option').length);
      return;
    }

    // radio: show/hide според allowed
    var $field = $root.find('#field_' + formId + '_' + fieldId);
    console.log('[GFCC] Looking for radio container: #field_' + formId + '_' + fieldId, 'found:', $field.length);

    if ($field.length) {
      var allowed = (choices || []).map(function (ch) { return String(ch.value); });
      console.log('[GFCC] RADIO field - allowed values:', allowed);

      var $inputs = $field.find('input[type=radio]');
      console.log('[GFCC] Found radio inputs:', $inputs.length);

      $inputs.each(function () {
        var $inp = $(this);
        var val = String($inp.val());
        var ok = allowed.indexOf(val) !== -1;

        // Gravity Forms използва .gchoice wrapper, не <li>
        var $wrapper = $inp.closest('.gchoice, li');
        console.log('[GFCC] Radio value "' + val + '" - allowed:', ok, '- wrapper found:', $wrapper.length);

        if (ok) {
          $wrapper.show();
        } else {
          if ($inp.is(':checked')) {
            $inp.prop('checked', false).trigger('change');
          }
          $wrapper.hide();
        }
      });
      return;
    }

    // checkbox (за бъдеще)
    var $checkboxContainer = $root.find('#field_' + formId + '_' + fieldId);
    console.log('[GFCC] Looking for checkbox container, found:', $checkboxContainer.length);

    if ($checkboxContainer.length) {
      var allowed = (choices || []).map(function (ch) { return String(ch.value); });
      var $inputs = $checkboxContainer.find('input[type=checkbox]');
      console.log('[GFCC] CHECKBOX field - found inputs:', $inputs.length, 'allowed:', allowed);

      $inputs.each(function () {
        var $inp = $(this);
        var val = String($inp.val());
        var ok = allowed.indexOf(val) !== -1;

        // Gravity Forms използва .gchoice wrapper, не <li>
        var $wrapper = $inp.closest('.gchoice, li');

        if (ok) {
          $wrapper.show();
        } else {
          if ($inp.is(':checked')) {
            $inp.prop('checked', false).trigger('change');
          }
          $wrapper.hide();
        }
      });
      return;
    }

    console.warn('[GFCC] Field ' + fieldId + ' not found or unsupported type');
  }

  function updateTarget(formCfg) {
    var formId = formCfg.formId;
    var mode = formCfg.mode || 'last_match';

    // DEBUG LOG
    console.log('[GFCC] Updating targets for form ' + formId);

    Object.keys(formCfg.targets || {}).forEach(function (tidStr) {
      var targetId = parseInt(tidStr, 10);
      var tcfg = formCfg.targets[tidStr];
      var matched = null;

      console.log('[GFCC] Processing target field ' + targetId);

      (tcfg.groups || []).some(function (grp) {
        if (!grp || grp.enabled === false) return false;

        var logic = (String(grp.logicType || 'all').toLowerCase() === 'any') ? 'any' : 'all';
        var rules = grp.rules || [];
        var results = rules.map(function (rule) {
          var fid = parseInt(rule.fieldId, 10);
          var cur = getFieldValue(formId, fid);
          console.log('[GFCC] Rule check: field ' + fid + ' value "' + cur + '" vs "' + rule.value + '" (operator: ' + rule.operator + ')');

          if (rule.operator === 'isnot') {
            return (cur !== String(rule.value));
          } else {
            return (cur === String(rule.value));
          }
        });

        var groupMatch = (logic === 'all') ? results.every(Boolean) : results.some(Boolean);
        console.log('[GFCC] Group match: ' + groupMatch, results);

        if (groupMatch) {
          var allowed = (grp.choices || []).map(function (v) { return String(v); });
          var filtered = (tcfg.originalChoices || []).filter(function (ch) {
            return allowed.indexOf(String(ch.value)) !== -1;
          });

          console.log('[GFCC] Matched! Applying ' + filtered.length + ' choices');
          matched = filtered;
          return (mode === 'first_match'); // прекъсни при first_match
        }
        return false;
      });

      var choicesToApply = matched || tcfg.originalChoices;
      console.log('[GFCC] Final choices count: ' + choicesToApply.length);
      applyChoices(formId, targetId, choicesToApply);
    });
  }

  function bindForm(formCfg) {
    var formId = formCfg.formId;
    var $root = $('#gform_' + formId);

    console.log('[GFCC] Binding form ' + formId, $root.length ? 'found' : 'NOT FOUND');

    if (!$root.length) return;

    // Първоначално прилагане
    updateTarget(formCfg);

    // Слушаме всички полета, използвани в правила
    var boundKey = 'gfcc-bound';
    var already = $root.data(boundKey) || {};

    Object.keys(formCfg.targets || {}).forEach(function (tid) {
      (formCfg.targets[tid].groups || []).forEach(function (grp) {
        (grp.rules || []).forEach(function (rule) {
          var fid = parseInt(rule.fieldId, 10);
          if (already[fid]) return;
          already[fid] = true;

          console.log('[GFCC] Binding events for field ' + fid);

          // text/select/textarea
          $root.on('change.gfcc keyup.gfcc', '#input_' + formId + '_' + fid, function () {
            console.log('[GFCC] Change detected on field ' + fid);
            updateTarget(formCfg);
          });

          // radio - ПОПРАВЕН SELECTOR
          $root.on('change.gfcc', 'input[name="input_' + formId + '_' + fid + '"]', function () {
            console.log('[GFCC] Radio change detected on field ' + fid);
            updateTarget(formCfg);
          });
        });
      });
    });

    $root.data(boundKey, already);
    console.log('[GFCC] Form ' + formId + ' fully bound');
  }

  // При всяко рендериране на форма (вкл. AJAX страници)
  $(document).on('gform_post_render', function (e, formId) {
    console.log('[GFCC] gform_post_render fired for form ' + formId);
    if (!window.GFCC_FORMS) {
      console.log('[GFCC] No GFCC_FORMS config found');
      return;
    }
    var cfg = window.GFCC_FORMS[formId];
    if (cfg) {
      console.log('[GFCC] Config found, binding...');
      bindForm(cfg);
    } else {
      console.log('[GFCC] No config for form ' + formId);
    }
  });

  // За случай, че скриптът се зареди след първоначалния render
  $(function () {
    console.log('[GFCC] DOM ready, checking for forms...');
    if (!window.GFCC_FORMS) {
      console.log('[GFCC] No GFCC_FORMS config found on DOM ready');
      return;
    }
    console.log('[GFCC] Found configs:', Object.keys(window.GFCC_FORMS));

    Object.keys(window.GFCC_FORMS).forEach(function (formId) {
      var cfg = window.GFCC_FORMS[formId];
      if (cfg) {
        console.log('[GFCC] Binding form ' + formId + ' from DOM ready');
        bindForm(cfg);
      }
    });
  });

})(jQuery);
