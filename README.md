# CentryOS Payment Gateway for WooCommerce

Accept payments via CentryOS hosted payment links. Fully compatible with WooCommerce Blocks.

## Features

- 🔒 Secure hosted payment pages
- 💳 Multiple payment methods (Card, Apple Pay, Google Pay, Cash App)
- 🔔 Real-time webhook notifications
- 🛒 WooCommerce Blocks compatible
- ⚙️ Flexible configuration options

## Requirements

- WordPress 5.8 or higher
- WooCommerce 6.0 or higher
- PHP 7.4 or higher

## Installation

### From ZIP File

1. Download the latest release ZIP
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Choose the ZIP file and click "Install Now"
4. Activate the plugin
5. Go to WooCommerce → Settings → Payments → CentryOS Payment Gateway
6. Enter your API credentials and configure settings

### From Source (Development)
```bash
# Clone repository
git clone https://github.com/GradientFI/centryos-woocommerce-gateway.git

# Move to WordPress plugins directory
mv centryos-woocommerce-gateway /path/to/wordpress/wp-content/plugins/

# Activate via WordPress admin or WP-CLI
wp plugin activate centryos-woocommerce-gateway
```

## Configuration

### Method 1: Admin Panel (Quick Setup)

1. Navigate to **WooCommerce → Settings → Payments**
2. Enable **CentryOS Payment Gateway**
3. Click **Manage**
4. Enter your credentials:
   - Client ID
5. Configure additional settings
6. Save changes

### Method 2: wp-config.php (Recommended for Security)

Add these constants to your `wp-config.php` file:
```php
define('CENTRYOS_API_SECRET', 'your-api-secret');
define('CENTRYOS_WEBHOOK_SECRET', 'your-webhook-secret');
```

**Benefits:**
- ✅ Credentials not stored in database
- ✅ Protected from database breaches
- ✅ Not visible in WordPress admin
- ✅ Easier credential rotation

## Webhook Setup

Configure this webhook URL in your CentryOS dashboard:
```
https://yoursite.com/wp-json/v1/wh/centryos/payment-complete
```

The webhook handles payment confirmation and automatically updates order status.

## Development

### Directory Structure
```
centryos-woocommerce-gateway/
├── assets/           # CSS, JS, and images
├── includes/         # PHP classes
├── languages/        # Translation files
├── centryos-woocommerce-gateway.php  # Main plugin file
├── uninstall.php     # Cleanup on uninstall
└── readme.txt        # WordPress.org readme
```

### Coding Standards

This plugin follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).
```bash
# Check coding standards
composer lint

# Auto-fix issues
composer format
```

### Building for Distribution
```bash
# Create distribution ZIP
./build.sh

# Output: release/centryos-woocommerce-gateway-{version}.zip
```

## Testing

### Manual Testing

1. Create a test product
2. Add to cart and proceed to checkout
3. Select CentryOS as payment method
4. Complete payment on CentryOS page
5. Verify order status updates via webhook

### Unit Tests (Coming Soon)
```bash
composer test
```

## Versioning

This project uses [Semantic Versioning](https://semver.org/):

- **MAJOR** version for incompatible API changes
- **MINOR** version for backwards-compatible functionality
- **PATCH** version for backwards-compatible bug fixes

See [CHANGELOG.md](CHANGELOG.md) for release history.

## Security

### Reporting Security Issues

Please report security vulnerabilities to: support@centryos.xyz

**Do not** create public GitHub issues for security vulnerabilities.

### Best Practices

- Store credentials in `wp-config.php` instead of database
- Keep WordPress and WooCommerce updated
- Use HTTPS on your site
- Configure webhook signature verification
- Regular security audits

## Support

- **Documentation**: [https://docs.centryos.xyz](https://docs.centryos.xyz)
- **Issues**: [GitHub Issues](hhttps://github.com/GradientFI/centryos-woocommerce-gateway/issues)
- **Email**: support@centryos.xyz

## License

This plugin is licensed under the GPL v2 or later.
```
Copyright (C) 2025 CentryOS

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

## Credits

Developed by [CentryOS](https://centryos.xyz)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for detailed release notes.