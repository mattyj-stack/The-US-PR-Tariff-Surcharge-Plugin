# The-US-PR-Tariff-Surcharge-Plugin
Adds a configurable tariff surcharge to eligible WooCommerce orders that ship to the United States, Puerto Rico, or any other locations you choose.

## Features

* Applies a percentage-based surcharge (10% by default) on qualifying "parts" line items after discounts, with an option to use the pre-discount subtotal instead.
* Supports filtering products by category and excluding virtual or downloadable products from the surcharge calculation.
* Detects shipments to the United States, Puerto Rico (as either a country or US state), and any extra destinations defined in the settings screen.
* Displays the surcharge as its own fee line across the cart, checkout, order confirmation, emails, and admin order pages.
* Provides a configurable modal notice and inline reminder on the cart (and optionally checkout) pages with an acknowledgement checkbox that blocks checkout until confirmed.
* Stores calculation metadata on the order for audit purposes, including destination, base amount, applied rate, surcharge value, and plugin version.
* Includes controls for taxability, tax class, logging order notes, and multi-currency compatibility by using the order currency during calculation.

## Configuration

Navigate to **WooCommerce → Settings → Tariff Surcharge** to adjust:

1. Enable/disable the surcharge and manage the destination list.
2. Set the tariff rate and define which products count as "parts" via categories or virtual/downloadable exclusions.
3. Choose whether to use prices after or before item-level discounts when calculating the base.
4. Configure tax behaviour, fee labelling, and the modal/inline notice copy, including an optional acknowledgement checkbox.
5. Toggle checkout logging to record calculation details as order notes during checkout.
