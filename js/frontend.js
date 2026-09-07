(function ($) {
  'use strict';

  // Global cache: formId -> model
  var GFCC_SIMPLE_MODELS = {};

  // Quick model: cache selectors and dependencies (source -> targets)
  function buildFormModel(formId, formConfig) {
    var $form = $('#gform_' + formId);
    if (!$form.length) return null;

    var model = {
      formId: formId,
      $form: $form,
      config: formConfig,
      targets: {},   // targetId -> { kind, $wrapper, $select?, original?, choices?, config }
      sources: {},   // fieldId -> { kind, $wrapper, $els, getValue() }
      depMap: {}     // fieldId -> Set(targetIds)
    };

    // Collect targets and deps
    Object.keys(formConfig.targets || {}).forEach(function (targetId) {
      var conf = formConfig.targets[targetId];
      var $wrapper = $form.find('#field_' + formId + '_' + targetId);
      if (!$wrapper.length) return;

      var $select = $wrapper.find('select');
      var target;

      if ($select.length) {
        // Target is <select>
        var original = (conf.originalChoices || []).map(function (ch) {
          return { value: String(ch.value), text: ch.text };
        });

        // Fallback: if the server didn't send a placeholder entry, keep
        // whatever empty-value option GF actually rendered.
        var hasBlank = original.some(function (o) { return o.value === ''; });
        if (!hasBlank) {
          var $blank = $select.children('option').filter(function () {
            return this.value === '';
          }).first();
          if ($blank.length) {
            original.unshift({ value: '', text: $blank.text() });
          }
        }
        target = { kind: 'select', $wrapper: $wrapper, $select: $select, original: original, config: conf };
      } else {
        // Radio/checkbox group
        var $inputs = $wrapper.find('.gfield_radio input, .gfield_checkbox input');
        var choices = [];
        $inputs.each(function () {
          var $input = $(this);
          var val = String($input.val());
          var $choice = $input.closest('.gchoice');
          choices.push({ value: val, $choice: $choice, $input: $input });
        });
        target = { kind: 'choices', $wrapper: $wrapper, choices: choices, config: conf };
      }

      model.targets[targetId] = target;

      // Deps graph: source field -> targets
      (conf.groups || []).forEach(function (group) {
        (group.rules || []).forEach(function (rule) {
          var fid = String(rule.fieldId);
          if (!model.depMap[fid]) model.depMap[fid] = new Set();
          model.depMap[fid].add(targetId);
        });
      });
    });

    // Collect sources (DYNAMIC DOM QUERYING FIX)
    Object.keys(model.depMap).forEach(function (fid) {
      var api = {
        $wrapper: $form.find('#field_' + formId + '_' + fid)
      };

      api.getValue = function () {
        var $currentWrapper = $('#field_' + formId + '_' + fid);
        if (!$currentWrapper.length) return '';

        var $select = $currentWrapper.find('select');
        if ($select.length) {
          return $select.val() || '';
        }

        // Radio group: an unchecked group has NO value. This must return
        // before the generic input fallback below, which would otherwise
        // hand back the first radio's value attribute whether or not it is
        // selected, making rules match before the user picks anything.
        var $radioInputs = $currentWrapper.find('input[type="radio"]');
        if ($radioInputs.length) {
          var $checkedRadio = $radioInputs.filter(':checked');
          return $checkedRadio.length ? ($checkedRadio.val() || '') : '';
        }

        // Checkbox group: same reasoning. An empty group is an empty list.
        var $checkInputs = $currentWrapper.find('input[type="checkbox"]');
        if ($checkInputs.length) {
          return $checkInputs.filter(':checked').map(function (i, el) {
            return $(el).val();
          }).get();
        }

        var $input = $currentWrapper
          .find('input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"]), textarea')
          .first();
        if ($input.length) {
          return $input.val() || '';
        }

        return '';
      };

      model.sources[fid] = api;
    });

    return model;
  }

  function evalRule(model, formId, rule) {
    var source = model.sources[String(rule.fieldId)];
    if (!source) {
        // console.warn('[GFCC Debug] Source field ' + rule.fieldId + ' not found.');
        return false;
    }
    var sourceValue = source.getValue();
    var ruleValue = rule.value;
    var isMatch = false;

    if (Array.isArray(sourceValue)) {
      switch (rule.operator) {
        case 'is':
        case 'contains':
          isMatch = sourceValue.indexOf(ruleValue) > -1; break;
        case 'isnot':
          isMatch = sourceValue.indexOf(ruleValue) === -1; break;
      }
    } else {
      var sv = String(sourceValue == null ? '' : sourceValue);
      switch (rule.operator) {
        case 'is':            isMatch = (sv === ruleValue); break;
        case 'isnot':         isMatch = (sv !== ruleValue); break;
        case 'contains':      isMatch = (sv.indexOf(ruleValue) > -1); break;
        case 'starts_with':   isMatch = sv.startsWith(ruleValue); break;
        case 'ends_with':     isMatch = sv.endsWith(ruleValue); break;
        case '>':
        case '<': {
          var a = parseFloat(sv), b = parseFloat(ruleValue);
          if (!isNaN(a) && !isNaN(b)) {
            isMatch = rule.operator === '>' ? a > b : a < b;
          }
          break;
        }
      }
    }

    // console.log(`[GFCC Debug] Rule Eval | Field: ${rule.fieldId} | Expected: "${ruleValue}" (${rule.operator}) | Actual Value: "${sourceValue}" | Match: ${isMatch}`);

    return isMatch;
  }

  function computeAllowedForTarget(model, formId, target) {
    var matched = null;

   // console.log(`[GFCC Debug] --- Evaluating Target Field ---`);

    (target.config.groups || []).some(function (group) {
      if (!group.enabled) return false;

     // console.log(`[GFCC Debug] Checking Group: "${group.label}" (Logic: ${group.logicType})`);

      var res = (group.rules || []).map(function (rule) { return evalRule(model, formId, rule); });
      var ok = res.length ? (group.logicType === 'any' ? res.some(Boolean) : res.every(Boolean)) : false;

     // console.log(`[GFCC Debug] Group "${group.label}" Result: ${ok ? 'PASSED' : 'FAILED'}`);

      if (ok) {
          matched = group;
          return true; // Спира до тук при първото съвпадение (First match wins)
      }
      return false;
    });

    if (!matched) {
       // console.log(`[GFCC Debug] No groups matched. Showing original choices.`);
        return null;
    }

    // console.log(`[GFCC Debug] Applying choices from Group: "${matched.label}"`);
    return new Set((matched.choices || []).map(function (v) { return String(v); }));
  }

  function applyTarget(model, formId, targetId) {
    var target = model.targets[targetId];
    if (!target) return;

    var allowedSet = computeAllowedForTarget(model, formId, target);

    if (target.kind === 'select') {
      var original = target.original; // [{value, text}]
      // The placeholder (value === '') is never a filterable choice - it must
      // survive every group so the field can still read as "nothing selected".
      var toUse = allowedSet
        ? original.filter(function (o) { return o.value === '' || allowedSet.has(o.value); })
        : original;

      var $sel = target.$select;
      // Compare the lists to avoid unnecessary DOM operations
      var currentVals = $sel.children('option').map(function (i, el) { return el.value; }).get();
      var nextVals = toUse.map(function (o) { return o.value; });

      var same = currentVals.length === nextVals.length &&
                 currentVals.every(function (v, i) { return v === nextVals[i]; });

      if (!same) {
        var frag = document.createDocumentFragment();
        toUse.forEach(function (o) {
          var opt = document.createElement('option');
          opt.value = o.value;
          opt.textContent = o.text;
          frag.appendChild(opt);
        });

        var curr = $sel.val();
        $sel.empty()[0].appendChild(frag);

        if (curr && nextVals.indexOf(String(curr)) > -1) {
          $sel.val(curr);
        } else {
          // No triggers
          $sel.prop('selectedIndex', 0);
        }

        $sel.trigger('chosen:updated');

        // Only announce a real value change. Field 184 is a source for dozens
        // of GF conditional logic rules, so an unconditional change event
        // re-evaluates the whole form on every render.
        var before = String(curr == null ? '' : curr);
        var after  = String($sel.val() == null ? '' : $sel.val());
        if (before !== after) {
          $sel.trigger('change');
        }
      }
    } else {
      // Radio/checkbos - show/hide without triggering events
      var allowAll = !allowedSet;
      target.choices.forEach(function (it) {
        var allow = allowAll || allowedSet.has(it.value);

        if (allow) {
          // Show if hidden
          if (it.$choice.css('display') === 'none') {
            it.$choice.show();
          }
        } else {
          //If selecte dbut no longer allowed remove the selection
          if (it.$input.prop('checked')) {
            it.$input.prop('checked', false);
          }
          // Hide choice
          if (it.$choice.css('display') !== 'none') {
            it.$choice.hide();
          }
        }
      });
    }
  }

  function applyTargetsForSource(model, formId, sourceFieldId) {
    var set = model.depMap[String(sourceFieldId)];
    if (!set) return;
    set.forEach(function (targetId) {
      applyTarget(model, formId, targetId);
    });
  }

  function applyAllTargets(model, formId) {
    Object.keys(model.targets).forEach(function (tid) {
      applyTarget(model, formId, tid);
    });
  }

  function bindHandlers(model) {
    var formId = model.formId;
    var $form = model.$form;

    $form.off('.gfccSimple');

    Object.keys(model.sources).forEach(function (fid) {
      var src = model.sources[fid];
      if (!src.$wrapper || !src.$wrapper.length) return;

      var wrapperId = src.$wrapper.attr('id');
      var selector = '#' + wrapperId + ' select, #' + wrapperId + ' input, #' + wrapperId + ' textarea';

      $form.on('change.gfccSimple blur.gfccSimple', selector, function (e) {

        if (e.type === 'blur' && (e.target.type === 'radio' || e.target.type === 'checkbox')) {
            return;
        }

        // console.log(`[GFCC Debug] Action! Event "${e.type}" fired on Source Field ${fid}. Initiating check...`);
        applyTargetsForSource(model, formId, fid);
      });
    });
  }

  function bindFormSimple(formId, formConfig) {
    var $form = $('#gform_' + formId);
    if (!$form.length) return;

    // Free previous model if any and events
    var prev = GFCC_SIMPLE_MODELS[formId];
    if (prev && prev.sources) {
      Object.keys(prev.sources).forEach(function (fid) {
        var src = prev.sources[fid];
        if (src.$els && src.$els.length) src.$els.off('.gfccSimple');
      });
    }

    var model = buildFormModel(formId, formConfig);
    if (!model) return;

    GFCC_SIMPLE_MODELS[formId] = model;

    bindHandlers(model);
    // Initial calculation of all targets
    applyAllTargets(model, formId);
  }

  // Hook on render
  $(document).on('gform_post_render', function (e, formId) {
    if (window.GFCC_FORMS && window.GFCC_FORMS[formId]) {
      bindFormSimple(formId, window.GFCC_FORMS[formId]);
    }
  });

  // For forms already on the page
  $(function () {
    if (window.GFCC_FORMS) {
      Object.keys(window.GFCC_FORMS).forEach(function (formId) {
        bindFormSimple(formId, window.GFCC_FORMS[formId]);
      });
    }
  });

})(jQuery);
