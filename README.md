# B2B for WooCommerce

**Version:** 1.0.1

**Requirements:** WordPress 6.2 or later, PHP 7.4 or later, WooCommerce

**Text Domain:** `b2b-woocommerce`

## Description

**B2B for WooCommerce** allows you to define B2B customer accounts and assign individual pricing rules to them. Prices are calculated based on the regular price of a product or a specific variation and then applied through WooCommerce pricing mechanisms.

The plugin provides the following features:

- Assigning customers to B2B accounts based on email addresses or email domains;
- Setting percentage discounts and fixed prices;
- Defining pricing rules for products, variations, and product categories;
- Resolving conflicts between matching rules according to a predefined priority;
- Displaying a **"Individual Price"** label on product pages;
- Integration with **Lowest Price History** for handling the 30-day lowest price label.

The plugin does not modify product prices stored in the catalog. Preferential prices are applied dynamically for recognized B2B customers.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [B2B Account Configuration](#b2b-account-configuration)
- [Preferential Pricing Configuration](#preferential-pricing-configuration)
- [Pricing Rules](#pricing-rules)
- [B2B Account Recognition](#b2b-account-recognition)
- [Individual Price Label](#individual-price-label)
- [Lowest Price History Integration](#lowest-price-history-integration)
- [Data Structure](#data-structure)
- [Permissions](#permissions)
- [Uninstallation](#uninstallation)
- [Changelog](#changelog)

## Requirements

- WordPress 6.2 or later;
- PHP 7.4 or later;
- WooCommerce plugin installed and activated.

The Omnibus label integration additionally requires the **Lowest Price History** plugin. Without it, the core B2B account and pricing rule functionality remains fully independent.

## Installation

1. Log in to your WordPress administration panel.
2. Navigate to **Plugins → Add New → Upload Plugin**.
3. Select the `b2b-woocommerce.zip` archive.
4. Install and activate the plugin.
5. The **B2B Customers** section and the **B2B Customer Pricing** submenu will appear in the administration menu.

Before installing or updating the plugin on a production store, it is recommended to create a backup of your files and database.

## B2B Account Configuration

B2B accounts are managed in the WordPress administration panel under **B2B Customers**. Each account has a name and a list of domains and specific email addresses used to assign logged-in users to the corresponding business account.

### Adding an Account

1. Open **B2B Customers**.
2. Add a new account and enter its name, such as the company or contractor name.
3. Enter the domains assigned to the account, e.g. `company.com`.
4. Enter specific email addresses that should be directly assigned to the account, e.g. `purchasing@company.com`.
5. Save the account.

Multiple values can be entered in the domain and email address fields. Each value can be placed on a separate line or separated by a comma or semicolon.

Domains should be entered without a protocol or path, e.g. `company.com` rather than `https://company.com/shop`.

Assigning a domain means that the account can include multiple users logging in with email addresses belonging to that domain. Only add domains that are intentionally assigned to the given business account.

### Editing and Deleting Accounts

Accounts can be edited or deleted in the **B2B Customers** section.

Pricing rules are associated with the account ID. Before deleting an account, make sure it is no longer required for managing the corresponding customer.

## Preferential Pricing Configuration

Pricing rules are configured under **B2B Customers → B2B Customer Pricing**.

### Adding a Pricing Rule

1. Select the B2B account for which the rule should apply.
2. Select the **Rule Scope**:
   - **Product (including variable products)** – applies a rule to a product. Percentage discounts also apply to its variations;
   - **Specific Variation** – applies a rule only to the selected variation;
   - **Product Category** – applies a rule to products belonging to the selected category.
3. Select the product, variation, or category, depending on the selected scope.
4. Select the **Price Type**:
   - **Percentage Discount** – a percentage discount calculated from the regular price;
   - **Fixed Price** – a specific price in the store currency.
5. Enter the value and save the rule.

After saving, the rule will appear in the list of active rules for the selected account. Existing rules can be removed from this list.

### Available Rule Types

| Scope | Percentage Discount | Fixed Price |
| --- | --- | --- |
| Simple Product | Yes | Yes |
| Variable Product (entire product) | Yes – applies to variations | No |
| Specific Variation | Yes | Yes |
| Product Category | Yes | No |

A fixed price cannot be assigned to an entire variable product because individual variations may have different prices. To set a specific price for a variation, create a **Specific Variation** rule.

## Pricing Rules

### Rule Priority

If multiple rules match a product, the rule with the highest priority is applied:

1. **Specific Variation** – highest priority;
2. **Product** – second priority;
3. **Product Category** – lowest priority.

If multiple category rules match, the plugin selects the lowest calculated price.

### Price Calculation

- Percentage discounts are calculated based on the regular price of the product or variation.
- A fixed price directly defines the preferential price.
- The B2B price replaces the WooCommerce sale price whenever a preferential price applies to a product or variation.
- Product catalog prices are not overwritten by the plugin.
- Pricing rules and variation prices are cached within a single request. The variation price hash includes the B2B account ID to reduce the risk of using cached prices belonging to another customer account.

## B2B Account Recognition

Preferential prices are applied based on the email address of the **logged-in user**.

The plugin checks account assignments in the following order:

1. It looks for an exact match of the user's email address in the list of assigned addresses.
2. If no exact match is found, it checks the email domain.
3. If the match identifies exactly one B2B account, that account's pricing rules are applied.
4. If the match identifies multiple accounts, no B2B prices are applied to prevent ambiguous account assignment.

In practice, the user must log in to their WordPress/WooCommerce account using an email address directly assigned to the relevant B2B account or belonging to one of its assigned domains.

Adding an email address or domain to the configuration does not create a WordPress user account or automatically log in the customer.

## Individual Price Label

The plugin provides the following WordPress action:

```php
do_action( 'b2bw_before_product_price', $product );
```

This action displays the **"Individual Price"** label when a preferential price exists for the current product or variation of the logged-in B2B customer.

### Displaying the Label in the Product Template

To display the label in a specific location on the product page, add the action call to the WooCommerce template or your child theme template.

For example, directly before displaying the product price:

```php
<?php do_action( 'b2bw_before_product_price', $product ); ?>

<strong><?php woocommerce_template_single_price(); ?></strong>
```

In this example, `$product` should be the WooCommerce object representing the current product.

If your theme uses a custom product template, place the action call in the appropriate location within that template.

It is recommended to make such changes in a child theme to prevent them from being overwritten during updates to the parent theme.

**Important:** Registering the action in the plugin does not guarantee that the label will be displayed. The template must explicitly call `do_action( 'b2bw_before_product_price', $product )`.

### Variable Products

For variable products, the plugin prepares a single label element and a list of variation IDs covered by individual pricing rules.

The label is displayed only when the currently selected variation has an assigned preferential price. When another variation is selected, the label is updated by the plugin's front-end script.

For simple products, the label is displayed whenever a preferential price exists for that product.

## Lowest Price History Integration

If the store uses the **Lowest Price History** plugin, the integration can replace the label displaying the lowest price from 30 days before a price reduction with **"Individual Price"** whenever a preferential B2B price applies to the current product or variation.

This prevents the standard Omnibus label from being displayed in a location where information about the customer's individual price should be presented instead.

### Important Information

- The **Lowest Price History** plugin must be installed and activated for its integration functionality to work.
- The label integration does not independently replace the seller's responsibility to assess legal obligations regarding the presentation of the lowest price before a reduction.
- The presentation should be verified on product pages, including variable products and both logged-in and logged-out users.

## Data Structure

The plugin creates its own tables in the WordPress database. Table names use the installation's database prefix:

- `{prefix}b2bw_accounts` – B2B account names and creation/update timestamps;
- `{prefix}b2bw_account_domains` – domains assigned to accounts;
- `{prefix}b2bw_account_emails` – specific email addresses assigned to accounts;
- `{prefix}b2bw_price_rules` – B2B account pricing rules.

The plugin also stores a database schema version option, which is used to manage database structure updates.

## Permissions

Managing B2B accounts and pricing rules is available to WordPress users with the `manage_woocommerce` capability.

Product and variation searches in the administration forms additionally require the `edit_products` capability.

## Uninstallation

When the plugin is uninstalled, it removes its own database tables:

- `{prefix}b2bw_price_rules`;
- `{prefix}b2bw_account_emails`;
- `{prefix}b2bw_account_domains`;
- `{prefix}b2bw_accounts`;

It also removes the `b2bw_db_version` option.

**Important:** Uninstallation permanently removes the plugin's configuration data from the database. Create a backup before uninstalling if you want to preserve your B2B accounts or pricing rules.

## Changelog

### 1.0.1

- Fixed a bug causing JavaScript console errors on product page.

### 1.0.0

- Added B2B account management with domain-based and email-based customer assignment;
- Added pricing rules for products, variations, and categories;
- Added rule priorities and lowest-price selection when multiple category rules match;
- Added dynamic preferential pricing through WooCommerce pricing mechanisms;
- Added the "Individual Price" label on product pages;
- Added label support for applicable product variations;
- Added integration with Lowest Price History for Omnibus label handling.