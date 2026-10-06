/**
 * Checkbox Missing Helper — Online Designer JS
 *
 * Handles two scenarios:
 *
 * 1. PAGE LOAD — calls the AJAX endpoint immediately on ready, so companion
 *    fields are created (or verified) as soon as the designer opens, without
 *    requiring a PHP-side page render cycle.
 *
 * 2. FIELD SAVE — a MutationObserver watches REDCap's field table for DOM
 *    changes that indicate a field was added or edited, then calls the AJAX
 *    endpoint to create, rename or update companion fields.
 *
 * When a companion field is created or renamed the page reloads so REDCap
 * redraws the field table. The banner messages are kept in sessionStorage
 * across that reload and shown once afterwards.
 *
 * checkboxMissingAjaxUrl, checkboxMissingProjectId and checkboxMissingCsrfToken
 * are passed from PHP.
 */
(function ($) {
    'use strict';

    if (typeof checkboxMissingAjaxUrl === 'undefined') return;

    var updateTimer   = null;
    var reloadPending = false;
    var PENDING_KEY   = 'checkboxMissingPending_' + checkboxMissingProjectId;

    /**
     * Calls the module AJAX endpoint and displays banners for anything
     * that was created, renamed, updated or relabelled.
     */
    function runFormulaUpdate() {
        $.ajax({
            url:      checkboxMissingAjaxUrl,
            method:   'POST',
            data:     {
                project_id:        checkboxMissingProjectId,
                redcap_csrf_token: checkboxMissingCsrfToken
            },
            dataType: 'json'
        })
            .done(function (response) {
                if (!response || response.status === 'error') {
                    console.error('CheckboxMissing: endpoint returned error', response);
                    return;
                }

                // New or renamed fields need the field table redrawn, so keep
                // the messages for after the reload rather than showing them now
                if ((response.created && response.created.length)
                    || (response.renamed && response.renamed.length)) {
                    savePending(response);
                    reloadWhenIdle();
                    return;
                }

                showMessages(response);
            })
            .fail(function (xhr, status, error) {
                console.error('CheckboxMissing: AJAX failed status=' + status + ' error=' + error);
                console.error('CheckboxMissing: response text=', xhr.responseText);
            });
    }

    /**
     * Shows one banner per kind of change. A new banner replaces an
     * earlier banner of the same kind; other banners stay until dismissed.
     */
    function showMessages(r) {
        if (r.created && r.created.length) {
            showBanner('created', 'success', r.created.length === 1
                ? 'Companion calc field created: ' + code(r.created[0].field)
                  + ' (placed after ' + code(r.created[0].source) + ', hidden with @HIDDEN).'
                : 'Companion calc fields created: '
                  + r.created.map(function (c) { return code(c.field); }).join(', ') + '.');
        }
        if (r.renamed && r.renamed.length) {
            var refs = [];
            r.renamed.forEach(function (c) { refs = refs.concat(c.references || []); });
            showBanner('renamed', 'success',
                'Companion calc field renamed to match its checkbox: '
                + r.renamed.map(function (c) {
                    return code(c.from) + ' &rarr; ' + code(c.to);
                }).join(', ') + '.'
                + (refs.length
                    ? ' References to the old name updated in: ' + refs.map(code).join(', ') + '.'
                    : '')
                + ' Alerts, Data Quality rules, reports and survey settings aren\'t updated'
                + ' automatically; check them for the old name.');
        }
        if (r.updated && r.updated.length) {
            showBanner('updated', 'info',
                'Companion calc field formula(s) updated '
                + '(checkbox options changed): '
                + r.updated.map(code).join(', ') + '.');
        }
        if (r.relabelled && r.relabelled.length) {
            showBanner('relabelled', 'info',
                'Companion field label(s) updated to the new format: '
                + r.relabelled.map(code).join(', ') + '.');
        }
    }

    /**
     * Injects a dismissable alert banner before the field table.
     *
     * The close button uses the module's own click handler rather than
     * Bootstrap's data-dismiss / data-bs-dismiss, which differ between
     * Bootstrap versions across REDCap releases.
     */
    function showBanner(kind, type, message) {
        $('.checkbox-missing-banner[data-kind="' + kind + '"]').remove();

        var banner = $(
            '<div class="alert alert-' + type + ' checkbox-missing-banner" '
            + 'data-kind="' + kind + '" role="alert" '
            + 'style="position:relative;clear:both;margin:10px 0;padding-right:40px;">'
            + '<button type="button" class="checkbox-missing-close" aria-label="Close" '
            + 'style="position:absolute;top:6px;right:10px;background:none;border:0;'
            + 'padding:2px 4px;font-size:20px;line-height:1;color:inherit;opacity:.6;cursor:pointer;">'
            + '&times;</button>'
            + '<b>Checkbox Missing Helper:</b> ' + message
            + '</div>'
        );
        // Above the instrument's field table; fall back to the top of the
        // page content if REDCap's markup ever changes
        var table = $('#questiontable, table.form_border, #design_forms_table').first();
        if (table.length) {
            table.before(banner);
        } else if ($('#center').length) {
            $('#center').prepend(banner);
        } else {
            $('body').prepend(banner);
        }
    }

    function code(field) {
        return '<code>' + escapeHtml(String(field)) + '</code>';
    }

    function escapeHtml(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // -------------------------------------------------------------------------
    // Messages kept across the page reload
    // -------------------------------------------------------------------------

    /**
     * Adds this response's messages to any already waiting, in case a
     * second save happens before the reload.
     */
    function savePending(r) {
        try {
            var p = JSON.parse(sessionStorage.getItem(PENDING_KEY) || '{}');
            ['created', 'renamed', 'updated', 'relabelled'].forEach(function (k) {
                p[k] = (p[k] || []).concat(r[k] || []);
            });
            sessionStorage.setItem(PENDING_KEY, JSON.stringify(p));
        } catch (e) {
            // Storage unavailable (e.g. blocked by browser settings): the
            // reload still happens, the banner just isn't shown
        }
    }

    /** Shows and clears messages saved before the last reload. */
    function showPending() {
        try {
            var raw = sessionStorage.getItem(PENDING_KEY);
            if (!raw) return;
            sessionStorage.removeItem(PENDING_KEY);
            showMessages(JSON.parse(raw));
        } catch (e) { /* see savePending */ }
    }

    /**
     * Reloads the page, but not while a REDCap dialog is open — e.g. the
     * notice REDCap shows after a rename listing fields that reference the
     * old name. Waits until the user closes it.
     */
    function reloadWhenIdle() {
        if (reloadPending) return;
        reloadPending = true;
        (function check() {
            if ($('.ui-dialog:visible').length) {
                setTimeout(check, 500);
            } else {
                window.location.reload();
            }
        }());
    }

    // -------------------------------------------------------------------------

    $(document).on('click', '.checkbox-missing-close', function () {
        $(this).closest('.checkbox-missing-banner').remove();
    });

    $(document).ready(function () {

        showPending();

        // --- Scenario 1: run immediately on page load ---
        runFormulaUpdate();

        // --- Scenario 2: watch for field saves via MutationObserver ---
        // REDCap updates the field table rows after every field add/edit/delete.
        // We watch for childList mutations on the table body and debounce them
        // into a single AJAX call, avoiding the fragility of parsing AJAX
        // response strings (which vary across REDCap versions).

        // The Online Designer renders one table per instrument; we observe
        // the shared parent that contains all of them.
        var observeTarget = document.getElementById('questiontable')
                         || document.querySelector('table.form_border tbody')
                         || document.querySelector('#design_forms_table');

        if (!observeTarget) return;

        var observer = new MutationObserver(function (mutations) {
            // Only react to node additions or removals (field row changes),
            // not attribute tweaks (hover states, etc.)
            var hasStructuralChange = mutations.some(function (m) {
                return m.type === 'childList' && (
                    m.addedNodes.length > 0 || m.removedNodes.length > 0
                );
            });

            if (!hasStructuralChange) return;

            // Debounce — REDCap can fire multiple mutations for one save,
            // so wait until they settle before calling the endpoint.
            clearTimeout(updateTimer);
            updateTimer = setTimeout(runFormulaUpdate, 600);
        });

        observer.observe(observeTarget, {
            childList: true,
            subtree:   true
        });
    });

}(window.jQuery));
