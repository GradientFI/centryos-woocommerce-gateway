<?php
/**
 * Uninstall script
 * 
 * Fired when the plugin is uninstalled
 *
 * @package CentryOS_Gateway
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin settings
delete_option('woocommerce_centryos_gateway_settings');

// Remove order meta data (optional - comment out if you want to keep order history)
// global $wpdb;
// $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_centryos_%'");
```

---

### Step 5: Create .gitignore
```
# .gitignore

# WordPress
wp-config.php
.htaccess

# IDE
.vscode/
.idea/
*.sublime-project
*.sublime-workspace

# OS
.DS_Store
Thumbs.db

# Node
node_modules/
package-lock.json

# Composer
/vendor/
composer.lock

# Build
*.zip
/release/

# Logs
*.log
error_log
```

---

### Step 6: Create .distignore (for deployment)
```
# .distignore
# Files to exclude from distribution ZIP

/.git
/.github
/node_modules
/tests
/.vscode
/.idea
.DS_Store
.gitignore
.distignore
composer.json
composer.lock
package.json
package-lock.json
phpcs.xml
README.md
CHANGELOG.md