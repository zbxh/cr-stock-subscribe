# Net_DNS2

Bundled runtime from [Net_DNS2 v1.5.5](https://github.com/mikepultz/netdns2/tree/v1.5.5), commit `ea39ef5a97d5c2b9893a8c35af7b5fd5b0e40bc9`.
BSD license: see LICENSE. Upstream runtime files are unchanged; autoload.php loads them from this plugin without changing the system include path.

Used for Webhook DNS resolution with socket timeouts and UDP-to-TCP fallback. This release supports the site's PHP 7.4 runtime. No Composer command or extra DNS service is required.
