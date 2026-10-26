/*
 * Shared confirmation modal.
 *
 * Replaces window.confirm() for POST actions (sign, approve, decline, revert,
 * delete). Browser confirms can't be styled, can't hold an input, and read as
 * a browser warning rather than part of the app.
 *
 * Any element carrying data-confirm-form opens the modal; confirming submits
 * the referenced hidden form. Event delegation on document means htmx-swapped
 * grid rows work with no re-binding.
 */
(function () {
  'use strict';

  var modal, titleEl, bodyEl, okBtn, cancelBtn, reasonWrap, reasonInput;
  var pendingFormId = null;
  var lastFocused = null;

  function els() {
    modal = document.getElementById('confirm-modal');
    if (!modal) return false;
    titleEl = document.getElementById('confirm-modal-title');
    bodyEl = document.getElementById('confirm-modal-body');
    okBtn = document.getElementById('confirm-modal-ok');
    cancelBtn = document.getElementById('confirm-modal-cancel');
    reasonWrap = document.getElementById('confirm-modal-reason-wrap');
    reasonInput = document.getElementById('confirm-modal-reason');
    return true;
  }

  function close() {
    if (!modal) return;
    modal.classList.remove('is-open');
    pendingFormId = null;
    reasonInput.value = '';
    reasonWrap.hidden = true;
    // Restore Cancel in case showAlertModal() hid it for a one-button alert.
    cancelBtn.style.display = '';
    // Return focus to whatever opened the modal, so keyboard users don't get
    // dumped at the top of the document.
    if (lastFocused && lastFocused.focus) lastFocused.focus();
    lastFocused = null;
  }

  function open(trigger) {
    if (!els()) return;
    lastFocused = trigger;
    pendingFormId = trigger.getAttribute('data-confirm-form');

    titleEl.textContent = trigger.getAttribute('data-confirm-title') || 'Are you sure?';
    bodyEl.textContent = trigger.getAttribute('data-confirm-body') || '';
    okBtn.textContent = trigger.getAttribute('data-confirm-label') || 'Confirm';

    // Tone the confirm button to match the action's severity.
    var variant = trigger.getAttribute('data-confirm-variant') || '';
    okBtn.className = '';
    if (variant === 'danger' || variant === 'warning') okBtn.classList.add('contrast');

    var wantsReason = trigger.getAttribute('data-confirm-reason') === '1';
    reasonWrap.hidden = !wantsReason;

    modal.classList.add('is-open');
    (wantsReason ? reasonInput : okBtn).focus();
  }

  function submit() {
    if (!pendingFormId) { close(); return; }
    var form = document.getElementById(pendingFormId);
    if (!form) { close(); return; }

    if (!reasonWrap.hidden) {
      var field = form.querySelector('input[name="reason"]');
      if (field) field.value = reasonInput.value;
    }

    var id = pendingFormId;
    pendingFormId = null;   // guard against a double-submit on rapid Enter
    close();
    document.getElementById(id).submit();
  }

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest ? e.target.closest('[data-confirm-form]') : null;
    if (trigger) {
      e.preventDefault();
      open(trigger);
      return;
    }
    if (!modal || !modal.classList.contains('is-open')) return;

    if (e.target === okBtn) { e.preventDefault(); submit(); return; }
    if (e.target === cancelBtn || e.target === modal) { e.preventDefault(); close(); }
  });

  document.addEventListener('keydown', function (e) {
    if (!modal || !modal.classList.contains('is-open')) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    // Enter in the reason field confirms rather than doing nothing.
    if (e.key === 'Enter' && e.target === reasonInput) { e.preventDefault(); submit(); }
  });

  /*
   * Make htmx's hx-confirm use this modal instead of window.confirm().
   *
   * htmx fires htmx:confirm before every request that has hx-confirm. Calling
   * preventDefault() stops its own dialog; issueRequest() proceeds. One
   * listener converts every hx-confirm in the app — grid deletes and the rest
   * — with no template changes, so a styled modal never sits next to a browser
   * dialog on the same screen.
   */
  document.addEventListener('htmx:confirm', function (e) {
    if (!e.detail.question) return;   // no hx-confirm on this element
    if (!els()) return;               // no modal on the page: let htmx handle it

    e.preventDefault();

    lastFocused = e.detail.elt;
    pendingFormId = null;

    titleEl.textContent = e.detail.elt.getAttribute('data-confirm-title') || 'Are you sure?';
    bodyEl.textContent = e.detail.question;
    okBtn.textContent = e.detail.elt.getAttribute('data-confirm-label') || 'Confirm';
    okBtn.className = 'contrast';
    reasonWrap.hidden = true;

    var proceed = function () { close(); e.detail.issueRequest(true); };

    // One-shot handlers so a cancelled confirm leaves nothing bound.
    var onOk = function () { cleanup(); proceed(); };
    var onCancel = function () { cleanup(); close(); };
    var onKey = function (ev) {
      if (ev.key === 'Escape') { cleanup(); close(); }
      if (ev.key === 'Enter') { ev.preventDefault(); cleanup(); proceed(); }
    };
    var onBackdrop = function (ev) { if (ev.target === modal) { cleanup(); close(); } };

    function cleanup() {
      okBtn.removeEventListener('click', onOk);
      cancelBtn.removeEventListener('click', onCancel);
      document.removeEventListener('keydown', onKey);
      modal.removeEventListener('click', onBackdrop);
    }

    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
    document.addEventListener('keydown', onKey);
    modal.addEventListener('click', onBackdrop);

    modal.classList.add('is-open');
    okBtn.focus();
  });

  /*
   * One-button informational popup - reuses the same modal markup/styling
   * as the confirm flow above, but with Cancel hidden and OK just closing
   * (no form to submit). pendingFormId stays null, so the existing click
   * handler's submit() call on OK now closes instead of no-op'ing.
   */
  window.showAlertModal = function (title, body) {
    if (!els()) return;
    lastFocused = document.activeElement;
    pendingFormId = null;

    titleEl.textContent = title || '';
    bodyEl.textContent = body || '';
    okBtn.textContent = 'OK';
    okBtn.className = '';
    reasonWrap.hidden = true;
    cancelBtn.style.display = 'none';

    modal.classList.add('is-open');
    okBtn.focus();
  };

  document.addEventListener('DOMContentLoaded', els);
})();
