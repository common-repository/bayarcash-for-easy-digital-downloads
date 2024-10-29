=== Bayarcash For Easy Digital Downloads ===
Contributors: webimpian
Tags: payment gateway, easy digital downloads, edd, bayarcash, ecommerce
Requires at least: 5.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Integrate Bayarcash payment solutions with your Easy Digital Downloads store.

== Description ==

The Bayarcash For Easy Digital Downloads plugin allows you to seamlessly integrate Bayarcash payment solutions into your Easy Digital Downloads powered WordPress store. This plugin provides a secure and efficient way for your customers to make payments using Bayarcash services.

= Features =

* Easy integration with Easy Digital Downloads
* Secure payment processing
* Support for sandbox testing
* Automatic transaction requery
* Customizable gateway settings

== Installation ==

1. Upload the `bayarcash-edd` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Downloads > Settings > Payment Gateways
4. Enable the Bayarcash payment gateway
5. Configure the Bayarcash settings with your account details

== Frequently Asked Questions ==

= Do I need a Bayarcash account to use this plugin? =

Yes, you need to have a Bayarcash account to use this plugin. You can sign up at [https://bayarcash.com](https://bayarcash.com).

= Is this plugin compatible with the latest version of Easy Digital Downloads? =

Yes, this plugin is tested and compatible with the latest version of Easy Digital Downloads.

= Where can I find documentation for bayarcash? =

You can find the documentation at [https://docs.bayarcash.com](https://docs.bayarcash.com).

== Screenshots ==

1. Bayarcash settings page
2. Checkout page with Bayarcash payment option

== Changelog ==

= 1.0.1 =
* Initial public release

== Upgrade Notice ==

= 1.0.1 =
Initial public release. No upgrade notices at this time.

== Third-party Libraries ==

This plugin uses the following third-party libraries:

1. Axios
   - Version: 0.26.1
   - Source: https://github.com/axios/axios
   - License: MIT

2. Vue.js
   - Version: 3.2.31
   - Source: https://github.com/vuejs/vue
   - License: MIT

3. Bayarcash PHP SDK
   - Version: 1.2.3
   - Source: https://github.com/webimpian/bayarcash-php-sdk
   - License: MIT

The minified versions of Axios and Vue.js are included in the plugin's `assets/js/` directory for performance reasons. You can find the uncompressed, developer versions at the GitHub repositories linked above.

The Bayarcash PHP SDK is used for server-side integration with the Bayarcash API.

== External Services ==

This plugin communicates with the Bayarcash API to process payments and verify transactions. Specifically:

* The plugin uses https://console.bayar.cash/api/v2/portals to verify PAT Tokens and retrieve the list of available payment portals.
* User payment data is transmitted securely to Bayarcash for transaction handling.
* The plugin utilizes the Bayarcash PHP SDK for server-side integration with the Bayarcash API.

For more information about Bayarcash's services, please visit:
* Bayarcash website: https://bayarcash.com
* Bayarcash API documentation: https://api.webimpian.support/bayarcash
* Bayarcash PHP SDK: https://github.com/webimpian/bayarcash-php-sdk
* Bayarcash Terms of Service: https://bayarcash.com/terms-conditions/
* Bayarcash Privacy Policy: https://bayarcash.com/privacy-policy/

By using this plugin, you agree to comply with Bayarcash's terms of service and privacy policy regarding data transmission and processing.

== Additional Info ==

For more information about Bayarcash, please visit [https://www.bayarcash.com](https://www.bayarcash.com).

This plugin is developed and maintained by Web Impian Sdn Bhd.