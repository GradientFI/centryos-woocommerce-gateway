const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { createElement } = window.wp.element;

const settings = getSetting("centryos_gateway_data", {});

const Label = (props) => {
  const { PaymentMethodLabel } = props.components;
  return createElement(PaymentMethodLabel, {
    text: decodeEntities(settings.title || "Pay with CentryOS"),
  });
};

const Content = () => {
  return createElement(
    "div",
    {
      style: {
        marginTop: "8px",
        fontSize: "14px",
        color: "#666",
      },
    },
    decodeEntities(settings.description || "")
  );
};

const CentryOSPaymentMethod = {
  name: "centryos_gateway",
  label: createElement(Label, null),
  content: createElement(Content, null),
  edit: createElement(Content, null),
  canMakePayment: () => true,
  ariaLabel: decodeEntities(settings.title || "Pay with CentryOS"),
  supports: {
    features: settings.supports || ["products"],
  },
  // Ensure modal is initialized for Blocks checkout
  placeOrderButtonLabel: decodeEntities(settings.title || "Pay with CentryOS"),
};

// Ensure modal script is available for Blocks checkout
if (typeof window.CentryOSModal === "undefined" && typeof centryosData !== "undefined") {
  // Modal will be initialized by the main modal script
  // This is just a fallback check
}

registerPaymentMethod(CentryOSPaymentMethod);
