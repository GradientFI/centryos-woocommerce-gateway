/**
 * CentryOS Payment Modal
 *
 * Handles modal/iframe payment flow instead of redirect
 *
 * @package CentryOS_Gateway
 * @since 1.1.0
 */

(function ($) {
  "use strict";

  const CentryOSModal = {
    modalOpen: false,
    orderId: null,
    pollInterval: null,

    /**
     * Initialize modal functionality
     */
    init: function () {
      // Intercept form submission for CentryOS gateway
      $(document.body).on("checkout_place_order", this.handleCheckoutSubmit.bind(this));

      // Listen for payment completion messages from iframe
      window.addEventListener("message", this.handlePaymentMessage.bind(this));

      // Handle URL hash changes (for payment completion redirect)
      $(window).on("hashchange", this.handleHashChange.bind(this));

      // Check if we have an order ID from session
      if (centryosData.orderId) {
        this.orderId = centryosData.orderId;
      }
    },

    /**
     * Handle checkout form submission
     */
    handleCheckoutSubmit: function (e, $form) {
      const paymentMethod = $('input[name="payment_method"]:checked').val();

      if (paymentMethod === "centryos_gateway") {
        e.preventDefault();

        // Get order ID from form or session
        const orderId = $form.find('input[name="order_id"]').val() || this.orderId;

        if (!orderId) {
          console.error("CentryOS Modal: Order ID not found");
          return false;
        }

        this.orderId = orderId;
        this.openModal(orderId);
        return false;
      }
    },

    /**
     * Open payment modal
     */
    openModal: function (orderId) {
      const self = this;

      // Show loading state
      this.showLoading();

      // Get payment URL via AJAX
      $.ajax({
        url: centryosData.ajaxUrl,
        type: "POST",
        data: {
          action: "centryos_get_payment_url",
          nonce: centryosData.nonce,
          order_id: orderId,
        },
        success: function (response) {
          if (response.success) {
            self.showModal(
              response.data.payment_url,
              response.data.order_id,
              response.data.return_url
            );
            // Start polling for payment status as fallback
            self.startPolling(response.data.order_id, response.data.return_url);
          } else {
            self.hideLoading();
            alert(
              "Error: " +
                (response.data.message || "Failed to initialize payment. Please try again.")
            );
          }
        },
        error: function (xhr, status, error) {
          self.hideLoading();
          console.error("CentryOS Modal AJAX Error:", error);
          alert("Failed to initialize payment. Please try again.");
        },
      });
    },

    /**
     * Show loading overlay
     */
    showLoading: function () {
      if ($("#centryos-modal-overlay").length === 0) {
        $("body").append(
          '<div id="centryos-modal-overlay" class="centryos-modal-overlay"><div class="centryos-modal-loading"><div class="centryos-spinner"></div><p>Loading payment...</p></div></div>'
        );
        $("#centryos-modal-overlay").fadeIn(300);
      }
    },

    /**
     * Hide loading overlay
     */
    hideLoading: function () {
      $("#centryos-modal-overlay").fadeOut(300, function () {
        $(this).remove();
      });
    },

    /**
     * Show payment modal with iframe
     */
    showModal: function (paymentUrl, orderId, returnUrl) {
      const modalHtml = `
                <div id="centryos-modal-overlay" class="centryos-modal-overlay">
                    <div class="centryos-modal-container">
                        <button class="centryos-modal-close" aria-label="Close">&times;</button>
                        <iframe 
                            id="centryos-payment-iframe" 
                            src="${paymentUrl}" 
                            frameborder="0"
                            allow="payment"
                            title="CentryOS Payment"
                        ></iframe>
                    </div>
                </div>
            `;

      // Remove existing modal if any
      $("#centryos-modal-overlay").remove();

      $("body").append(modalHtml).addClass("centryos-modal-open");
      $("#centryos-modal-overlay").fadeIn(300);
      this.modalOpen = true;

      // Store return URL for later use
      $("#centryos-modal-overlay").data("return-url", returnUrl);
      $("#centryos-modal-overlay").data("order-id", orderId);

      // Close button handler
      $(".centryos-modal-close").on("click", function () {
        CentryOSModal.closeModal();
      });

      // Close on overlay click (outside modal)
      $("#centryos-modal-overlay").on("click", function (e) {
        if (e.target === this) {
          CentryOSModal.closeModal();
        }
      });

      // Prevent ESC key from closing (let user complete payment)
      // $(document).on('keydown.centryos-modal', function(e) {
      //     if (e.keyCode === 27 && CentryOSModal.modalOpen) {
      //         e.preventDefault();
      //     }
      // });
    },

    /**
     * Close payment modal
     */
    closeModal: function () {
      this.modalOpen = false;
      this.stopPolling();

      $("#centryos-modal-overlay").fadeOut(300, function () {
        $(this).remove();
        $("body").removeClass("centryos-modal-open");
      });
    },

    /**
     * Handle payment completion message from iframe
     */
    handlePaymentMessage: function (event) {
      // Listen for payment completion messages from CentryOS iframe
      // Adjust based on CentryOS's postMessage API
      if (event.data && typeof event.data === "object") {
        if (
          event.data.type === "centryos_payment_complete" ||
          event.data.event === "payment_complete"
        ) {
          this.handlePaymentComplete(event.data.redirectUrl);
        }
      }
    },

    /**
     * Handle URL hash changes (for payment completion)
     */
    handleHashChange: function () {
      const hash = window.location.hash;
      if (hash && hash.includes("centryos_payment_complete")) {
        this.handlePaymentComplete();
      }
    },

    /**
     * Handle payment completion
     */
    handlePaymentComplete: function (redirectUrl) {
      this.stopPolling();
      this.closeModal();

      // Redirect to thank you page
      const returnUrl =
        $("#centryos-modal-overlay").data("return-url") || redirectUrl || centryosData.checkoutUrl;

      if (returnUrl) {
        window.location.href = returnUrl;
      } else {
        // Fallback: reload page
        window.location.reload();
      }
    },

    /**
     * Start polling for payment status (fallback method)
     */
    startPolling: function (orderId, returnUrl) {
      const self = this;
      let pollCount = 0;
      const maxPolls = 100; // Poll for up to 5 minutes (100 * 3 seconds)

      this.pollInterval = setInterval(function () {
        pollCount++;

        if (pollCount > maxPolls) {
          self.stopPolling();
          return;
        }

        // Check if modal is still open
        if (!self.modalOpen) {
          self.stopPolling();
          return;
        }

        // Poll order status via AJAX (if endpoint exists)
        // This is a fallback - primary method should be postMessage
        $.ajax({
          url: centryosData.ajaxUrl,
          type: "POST",
          data: {
            action: "centryos_check_payment_status",
            nonce: centryosData.nonce,
            order_id: orderId,
          },
          success: function (response) {
            if (response.success && response.data && response.data.paid) {
              self.handlePaymentComplete(returnUrl);
            }
          },
          error: function () {
            // Silently fail - continue polling
          },
        });
      }, 3000); // Poll every 3 seconds
    },

    /**
     * Stop polling for payment status
     */
    stopPolling: function () {
      if (this.pollInterval) {
        clearInterval(this.pollInterval);
        this.pollInterval = null;
      }
    },
  };

  // Initialize when document is ready
  $(document).ready(function () {
    if (typeof centryosData !== "undefined") {
      CentryOSModal.init();
    }
  });

  // Expose to global scope for potential external access
  window.CentryOSModal = CentryOSModal;
})(jQuery);
