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

    // Collect sources
    Object.keys(model.depMap).forEach(function (fid) {
      var $wrapper = $form.find('#field_' + formId + '_' + fid);
      var api = { $wrapper: $wrapper };

      var $select = $wrapper.find('select');
      if ($select.length) {
        api.kind = 'select';
        api.$els = $select;
        api.getValue = function () { return $select.val() || ''; };
      } else {
        var $radios = $wrapper.find('.gfield_radio input[type="radio"]');
        var $checks = $wrapper.find('.gfield_checkbox input[type="checkbox"]');
        if ($radios.length) {
          api.kind = 'radio';
          api.$els = $radios;
          api.getValue = function () {
            var $c = $radios.filter(':checked');
            return $c.length ? $c.val() : '';
          };
        } else if ($checks.length) {
          api.kind = 'checkbox';
          api.$els = $checks;
          api.getValue = function () {
            return $checks.filter(':checked').map(function (i, el) { return $(el).val(); }).get();
          };
        } else {
          var $input = $wrapper.find('input, textarea').first();
          api.kind = 'text';
          api.$els = $input;
          api.getValue = function () { return $input.val() || ''; };
        }
      }

      model.sources[fid] = api;
    });

    return model;
  }

  function evalRule(model, formId, rule) {
    var source = model.sources[String(rule.fieldId)];
    if (!source) return false;
    var sourceValue = source.getValue();
    var ruleValue = rule.value;

    if (Array.isArray(sourceValue)) {
      switch (rule.operator) {
        case 'is':
        case 'contains':
          return sourceValue.indexOf(ruleValue) > -1;
        case 'isnot':
          return sourceValue.indexOf(ruleValue) === -1;
        default:
          return false;
      }
    }

    var sv = String(sourceValue == null ? '' : sourceValue);
    switch (rule.operator) {
      case 'is':            return sv === ruleValue;
      case 'isnot':         return sv !== ruleValue;
      case 'contains':      return sv.indexOf(ruleValue) > -1;
      case 'starts_with':   return sv.startsWith(ruleValue);
      case 'ends_with':     return sv.endsWith(ruleValue);
      case '>':
      case '<': {
        var a = parseFloat(sv), b = parseFloat(ruleValue);
        if (isNaN(a) || isNaN(b)) return false;
        return rule.operator === '>' ? a > b : a < b;
      }
      default: return false;
    }
  }

  function computeAllowedForTarget(model, formId, target) {
    var matched = null;
    (target.config.groups || []).some(function (group) {
      if (!group.enabled) return false;
      var res = (group.rules || []).map(function (rule) { return evalRule(model, formId, rule); });
      var ok = res.length ? (group.logicType === 'any' ? res.some(Boolean) : res.every(Boolean)) : false;
      if (ok) { matched = group; return true; }
      return false;
    });

    if (!matched) return null; // no match -> use original
    return new Set((matched.choices || []).map(function (v) { return String(v); }));
  }

  function applyTarget(model, formId, targetId) {
    var target = model.targets[targetId];
    if (!target) return;

    var allowedSet = computeAllowedForTarget(model, formId, target);

    if (target.kind === 'select') {
      var original = target.original; // [{value, text}]
      var toUse = allowedSet ? original.filter(function (o) { return allowedSet.has(o.value); }) : original;

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

    // Remove handlers if any
    Object.keys(model.sources).forEach(function (fid) {
      var src = model.sources[fid];
      if (!src.$els || !src.$els.length) return;
      src.$els.off('.gfccSimple');
    });

    // Attach minimal handlers
    Object.keys(model.sources).forEach(function (fid) {
      var src = model.sources[fid];
      if (!src.$els || !src.$els.length) return;

      var handler = function () {
        applyTargetsForSource(model, formId, fid);
      };

      if (src.kind === 'text') {
        src.$els.on('blur.gfccSimple', handler);
      } else if (src.kind === 'select') {
        src.$els.on('change.gfccSimple', handler);
      } else if (src.kind === 'radio' || src.kind === 'checkbox') {
        src.$els.on('change.gfccSimple', handler);
      } else {
        // fallback
        src.$els.on('change.gfccSimple', handler);
      }
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
