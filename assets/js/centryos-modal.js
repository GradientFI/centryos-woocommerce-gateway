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
      const self = this;

      $(document.body).on("checkout_place_order", this.handleCheckoutSubmit.bind(this));

      this.interceptBlocksCheckout();

      window.addEventListener("message", this.handlePaymentMessage.bind(this));

      $(window).on("hashchange", this.handleHashChange.bind(this));

      if (centryosData.orderId) {
        this.orderId = centryosData.orderId;
      }

      if (typeof window.wp !== "undefined" && window.wp.hooks) {
        window.wp.hooks.addAction(
          "wc.blocks-checkout.set-order-id",
          "centryos",
          function (orderId) {
            if (self.getBlocksPaymentMethod() === "centryos_gateway") {
              self.orderId = orderId;
              setTimeout(function () {
                self.openModal(orderId);
              }, 200);
            }
          }
        );
      }

      $(document.body).on("centryos_checkout_complete", function (e, orderId) {
        if (orderId) {
          self.orderId = orderId;
          setTimeout(function () {
            self.openModal(orderId);
          }, 100);
        }
      });

      setTimeout(function () {
        self.checkForPendingOrder();
      }, 500);
    },

    /**
     * Check for pending order that needs modal
     */
    checkForPendingOrder: function () {
      if (this.getBlocksPaymentMethod() === "centryos_gateway") {
        const urlParams = new URLSearchParams(window.location.search);
        const orderId = urlParams.get("order_id") || urlParams.get("order") || this.orderId;

        if (orderId && !this.modalOpen) {
          this.orderId = orderId;
          this.openModal(orderId);
        }
      }
    },

    /**
     * Intercept WooCommerce Blocks checkout API calls
     */
    interceptBlocksCheckout: function () {
      const self = this;

      const originalFetch = window.fetch;
      window.fetch = function (...args) {
        const url = args[0];

        if (typeof url === "string" && url.includes("/wc/store/v1/checkout")) {
          let requestBody = null;
          let paymentMethodFromRequest = null;

          if (args[1] && args[1].body) {
            try {
              if (typeof args[1].body === "string") {
                requestBody = JSON.parse(args[1].body);
                paymentMethodFromRequest = requestBody.payment_method;
              } else if (args[1].body instanceof FormData) {
                if (args[1].body.has("payment_method")) {
                  paymentMethodFromRequest = args[1].body.get("payment_method");
                }
              } else if (args[1].body instanceof ReadableStream) {
              } else {
                requestBody = args[1].body;
                paymentMethodFromRequest = requestBody.payment_method;
              }
            } catch (e) {}
          }

          if (!paymentMethodFromRequest && args[1]) {
            if (args[1].payment_method) {
              paymentMethodFromRequest = args[1].payment_method;
            }
          }

          return originalFetch.apply(this, args).then(function (response) {
            const clonedResponse = response.clone();

            let paymentMethod = null;

            if (paymentMethodFromRequest) {
              paymentMethod = paymentMethodFromRequest;
            }

            if (!paymentMethod) {
              paymentMethod = self.getBlocksPaymentMethod();
            }

            clonedResponse
              .json()
              .then(function (data) {
                if (!paymentMethod && data && data.payment_method) {
                  paymentMethod = data.payment_method;
                }

                if (paymentMethod === "centryos_gateway") {
                  if (data && data.order_id && data.payment_result) {
                    const paymentResult = data.payment_result;

                    const isEmptyRedirect =
                      paymentResult.redirect_url === "" ||
                      (paymentResult.payment_details &&
                        paymentResult.payment_details.find(
                          (d) => d.key === "redirect" && d.value === ""
                        ));

                    if (isEmptyRedirect && paymentResult.payment_status === "success") {
                      self.orderId = data.order_id;
                      setTimeout(function () {
                        self.openModal(data.order_id);
                      }, 100);
                    }
                  }
                }
              })
              .catch(function (error) {});

            return response;
          });
        }

        return originalFetch.apply(this, args);
      };

      const originalXHROpen = XMLHttpRequest.prototype.open;
      const originalXHRSend = XMLHttpRequest.prototype.send;

      XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this._centryos_url = url;
        return originalXHROpen.apply(this, [method, url, ...rest]);
      };

      XMLHttpRequest.prototype.send = function (...args) {
        const self_xhr = this;
        const url = this._centryos_url;

        if (typeof url === "string" && url.includes("/wc/store/v1/checkout")) {
          let requestBody = null;
          if (args[0]) {
            try {
              if (typeof args[0] === "string") {
                if (args[0].includes("action=") || args[0].includes("&")) {
                  return originalXHRSend.apply(this, args);
                } else {
                  requestBody = JSON.parse(args[0]);
                }
              } else if (args[0] instanceof FormData) {
                requestBody = {};
                if (args[0].has("payment_method")) {
                  requestBody.payment_method = args[0].get("payment_method");
                }
              } else {
                requestBody = args[0];
              }
            } catch (e) {
              return originalXHRSend.apply(this, args);
            }
          }
          this.addEventListener("load", function () {
            if (self_xhr.status === 200 || self_xhr.status === 201) {
              try {
                const data = JSON.parse(self_xhr.responseText);

                let paymentMethod = null;
                if (requestBody && requestBody.payment_method) {
                  paymentMethod = requestBody.payment_method;
                } else {
                  paymentMethod = self.getBlocksPaymentMethod();
                }

                if (
                  paymentMethod === "centryos_gateway" &&
                  data &&
                  data.order_id &&
                  data.payment_result
                ) {
                  const paymentResult = data.payment_result;

                  const isEmptyRedirect =
                    paymentResult.redirect_url === "" ||
                    (paymentResult.payment_details &&
                      paymentResult.payment_details.find(
                        (d) => d.key === "redirect" && d.value === ""
                      ));

                  if (isEmptyRedirect && paymentResult.payment_status === "success") {
                    self.orderId = data.order_id;
                    setTimeout(function () {
                      self.openModal(data.order_id);
                    }, 100);
                  }
                }
              } catch (error) {}
            }
          });
        }

        return originalXHRSend.apply(this, args);
      };
    },

    /**
     * Get payment method from Blocks checkout
     */
    getBlocksPaymentMethod: function () {
      const selectors = [
        'input[name="payment_method"]:checked',
        "[data-payment-method-id]",
        ".wc-block-components-payment-method input:checked",
        "#payment_method_centryos_gateway",
      ];

      for (let i = 0; i < selectors.length; i++) {
        const element = document.querySelector(selectors[i]);
        if (element) {
          const value =
            element.value ||
            element.getAttribute("data-payment-method-id") ||
            element.id.replace("payment_method_", "");
          if (value === "centryos_gateway") {
            return "centryos_gateway";
          }
        }
      }

      const centryosRadio = document.querySelector("#payment_method_centryos_gateway");
      if (centryosRadio) {
        const isChecked = centryosRadio.checked;
        const isActive = centryosRadio.closest(".is-active");
        if (isChecked || isActive) {
          return "centryos_gateway";
        }
      }

      return null;
    },

    /**
     * Handle checkout form submission
     */
    handleCheckoutSubmit: function (e, $form) {
      const paymentMethod = $('input[name="payment_method"]:checked').val();

      if (paymentMethod === "centryos_gateway") {
        e.preventDefault();

        const orderId = $form.find('input[name="order_id"]').val() || this.orderId;

        if (!orderId) {
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

      if (!orderId) {
        return;
      }

      this.showLoading();

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
          alert("Failed to initialize payment. Please check console for details.");
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
      const self = this;
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

      $("#centryos-modal-overlay").remove();

      $("body").append(modalHtml).addClass("centryos-modal-open");
      $("#centryos-modal-overlay").fadeIn(300);
      this.modalOpen = true;

      $("#centryos-modal-overlay").data("return-url", returnUrl);
      $("#centryos-modal-overlay").data("order-id", orderId);

      $(".centryos-modal-close").on("click", function () {
        CentryOSModal.closeModal();
      });

      const iframe = document.getElementById("centryos-payment-iframe");
      if (iframe) {
        const urlCheckInterval = setInterval(function () {
          try {
            const iframeUrl = iframe.contentWindow.location.href;

            if (
              iframeUrl.includes("success") ||
              iframeUrl.includes("complete") ||
              iframeUrl.includes("thank") ||
              iframeUrl.includes("order-received") ||
              iframeUrl === returnUrl
            ) {
              clearInterval(urlCheckInterval);
              self.handlePaymentComplete(returnUrl);
            }
          } catch (e) {}
        }, 2000);

        $("#centryos-modal-overlay").data("url-check-interval", urlCheckInterval);

        iframe.addEventListener("load", function () {
          try {
            const iframeUrl = iframe.contentWindow.location.href;

            if (
              iframeUrl.includes("success") ||
              iframeUrl.includes("complete") ||
              iframeUrl.includes("thank") ||
              iframeUrl.includes("order-received")
            ) {
              clearInterval(urlCheckInterval);
              self.handlePaymentComplete(returnUrl);
            }
          } catch (e) {}
        });
      }
    },

    /**
     * Close payment modal
     */
    closeModal: function () {
      this.modalOpen = false;
      this.stopPolling();

      const urlCheckInterval = $("#centryos-modal-overlay").data("url-check-interval");
      if (urlCheckInterval) {
        clearInterval(urlCheckInterval);
      }

      $("#centryos-modal-overlay").fadeOut(300, function () {
        $(this).remove();
        $("body").removeClass("centryos-modal-open");
      });
    },

    /**
     * Handle payment completion message from iframe
     */
    handlePaymentMessage: function (event) {
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

      const returnUrl =
        $("#centryos-modal-overlay").data("return-url") || redirectUrl || centryosData.checkoutUrl;

      if (returnUrl) {
        window.location.href = returnUrl;
      } else {
        window.location.reload();
      }
    },

    /**
     * Start polling for payment status (fallback method)
     *
     * How modal closing works:
     * 1. User completes payment in CentryOS iframe
     * 2. CentryOS sends webhook to: /wp-json/v1/wh/centryos/payment-complete
     * 3. Webhook marks order as paid in WooCommerce
     * 4. This polling function checks order status every 2 seconds
     * 5. When order is detected as paid, modal closes and redirects to thank you page
     */
    startPolling: function (orderId, returnUrl) {
      const self = this;
      let pollCount = 0;
      const maxPolls = 150;

      this.pollInterval = setInterval(function () {
        pollCount++;

        if (pollCount > maxPolls) {
          self.stopPolling();
          return;
        }

        if (!self.modalOpen) {
          self.stopPolling();
          return;
        }

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
              const redirectUrl = response.data.redirect_url || returnUrl;
              self.handlePaymentComplete(redirectUrl);
            }
          },
          error: function (xhr, status, error) {},
        });
      }, 2000);
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

  $(document).ready(function () {
    if (typeof centryosData !== "undefined") {
      CentryOSModal.init();
    }
  });

  window.CentryOSModal = CentryOSModal;

  window.triggerCentryOSModal = function (orderId) {
    if (orderId) {
      CentryOSModal.openModal(orderId);
    }
  };
})(jQuery);
