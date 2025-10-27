(function ($) {
    'use strict';

    function initTariffModal(context) {
        var data = window.wcTariffSurchargeData || {};
        if (!data.showModal) {
            removeModal();
            return;
        }

        var modalRequired = (data.isCart && data.enableModalCart) || (data.isCheckout && data.enableModalCheckout);
        if (!modalRequired) {
            removeModal();
            return;
        }

        if (isAcknowledged(data)) {
            disableButtons(false, data);
            removeModal();
            return;
        }

        if (!document.body || document.getElementById('us-pr-tariff-modal')) {
            updateAcknowledgementState();
            return;
        }

        var modal = document.createElement('div');
        modal.id = 'us-pr-tariff-modal';
        modal.className = 'us-pr-tariff-modal';
        modal.innerHTML = getModalTemplate(data);
        document.body.appendChild(modal);

        bindModalEvents(modal, data);
        toggleModal(true, data);
    }

    function getModalTemplate(data) {
        var ackHtml = '';
        if (data.requireAck) {
            ackHtml = '<label class="us-pr-tariff-ack"><input type="checkbox" id="us-pr-tariff-ack-box" />' + data.ackLabel + '</label>';
        }

        return '' +
            '<div class="us-pr-tariff-modal__backdrop"></div>' +
            '<div class="us-pr-tariff-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="us-pr-tariff-modal-title">' +
            '  <div class="us-pr-tariff-modal__content">' +
            '    <h2 id="us-pr-tariff-modal-title">' + data.modalTitle + '</h2>' +
            '    <div class="us-pr-tariff-modal__body">' + data.modalBody + '</div>' +
            (ackHtml ? '    <div class="us-pr-tariff-modal__ack">' + ackHtml + '</div>' : '') +
            '    <div class="us-pr-tariff-modal__actions">' +
            '      <button type="button" class="button button-primary" id="us-pr-tariff-modal-close">' + wcTariffModalL10n.ok + '</button>' +
            '    </div>' +
            '  </div>' +
            '</div>';
    }

    function bindModalEvents(modal, data) {
        var closeButton = modal.querySelector('#us-pr-tariff-modal-close');
        if (closeButton) {
            closeButton.addEventListener('click', function () {
                if (!data.requireAck) {
                    setAcknowledged(true, data);
                }
                toggleModal(false, data);
            });
            if (data.requireAck && !isAcknowledged(data)) {
                closeButton.disabled = true;
            }
        }

        if (data.requireAck) {
            var ackBox = modal.querySelector('#us-pr-tariff-ack-box');
            if (ackBox) {
                ackBox.addEventListener('change', function () {
                    setAcknowledged(ackBox.checked, data);
                    updateButtons(data);
                    if (ackBox.checked) {
                        if (closeButton) {
                            closeButton.disabled = false;
                        }
                        toggleModal(false, data);
                    }
                });
                ackBox.checked = isAcknowledged(data);
                if (ackBox.checked && closeButton) {
                    closeButton.disabled = false;
                }
            }
        }

        updateButtons(data);
    }

    function toggleModal(forceOpen, data) {
        var modal = document.getElementById('us-pr-tariff-modal');
        if (!modal) {
            return;
        }

        var acked = isAcknowledged(data);
        if (data.requireAck && !acked) {
            disableButtons(true, data);
        } else {
            disableButtons(false, data);
        }

        modal.classList.toggle('is-visible', !!forceOpen && !(data.requireAck && acked));

        if (acked) {
            modal.parentNode && modal.parentNode.removeChild(modal);
        }
    }

    function disableButtons(disabled, data) {
        if (data.isCart) {
            var $buttons = $('.wc-proceed-to-checkout a.checkout-button, .wc-proceed-to-checkout button.checkout-button');
            $buttons.prop('disabled', disabled).toggleClass('disabled', disabled).attr('aria-disabled', disabled);
            if (disabled) {
                $buttons.on('click.usPrTariff', function (event) {
                    event.preventDefault();
                });
            } else {
                $buttons.off('click.usPrTariff');
            }
        }
        if (data.isCheckout) {
            $('#place_order').prop('disabled', disabled).attr('aria-disabled', disabled);
        }
    }

    function isAcknowledged(data) {
        var key = getAckStorageKey(data);
        try {
            return sessionStorage.getItem(key) === '1';
        } catch (e) {
            return false;
        }
    }

    function setAcknowledged(value, data) {
        var key = getAckStorageKey(data);
        try {
            if (value) {
                sessionStorage.setItem(key, '1');
            } else {
                sessionStorage.removeItem(key);
            }
        } catch (e) {
            // Storage may be unavailable. Ignore.
        }
    }

    function getAckStorageKey(data) {
        return (data.sessionAckKey || 'us_pr_tariff_ack') + ':' + (data.destinationKey || '');
    }

    function updateButtons(data) {
        disableButtons(data.requireAck && !isAcknowledged(data), data);
    }

    function updateAcknowledgementState() {
        var data = window.wcTariffSurchargeData || {};
        if (data.requireAck) {
            updateButtons(data);
        }
    }

    function removeModal() {
        var modal = document.getElementById('us-pr-tariff-modal');
        if (modal && modal.parentNode) {
            modal.parentNode.removeChild(modal);
        }
    }

    function ensureInlineNotice() {
        var data = window.wcTariffSurchargeData || {};
        if (!data.inlineNotice) {
            return;
        }

        var targetSelector = data.isCheckout ? '.woocommerce-checkout-review-order-table' : '.cart_totals';
        var container = document.querySelector(targetSelector);
        if (!container) {
            return;
        }

        var existing = container.previousElementSibling;
        if (existing && existing.classList && existing.classList.contains('us-pr-tariff-inline-notice')) {
            existing.innerHTML = data.inlineNotice;
            return;
        }
    }

    $(document).ready(function () {
        window.wcTariffModalL10n = window.wcTariffModalL10n || { ok: 'OK' };
        initTariffModal(document.body);
        ensureInlineNotice();
    });

    $(document.body).on('updated_wc_div updated_cart_totals updated_checkout wc_fragments_refreshed', function () {
        initTariffModal(document.body);
        ensureInlineNotice();
    });
})(jQuery);
