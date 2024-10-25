<?php declare(strict_types=1);
/**
 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
 *
 * Unauthorized copying of this file, via any medium, is
 * strictly prohibited without consent. Any dissemination of
 * material herein is prohibited.
 *
 * For licensing inquiries email <licensing@apisnetworks.com>
 *
 * Written by Matt Saladna <matt@apisnetworks.com>, July 2020
 */

namespace Module\Support\Webapps\App\Type\Unknown\Reconfiguration;

use Module\Support\Webapps\App\Reconfigurator;
use Module\Support\Webapps\Contracts\ReconfigurableProperty;
use Opcenter\Http\Apache\Maps\HSTS\Mode;

class Ssl extends Reconfigurator implements ReconfigurableProperty
{
	public function handle(&$val): bool
	{
		$hostname = $this->app->getHostname();
		if (false === strpos($hostname, '.')) {
			$tmp = $this->web_normalize_hostname($hostname);
			warn("Configuring SSL on global subdomains not fully supported - appending `%s' to subdomain",
				substr($tmp, \strlen($hostname) + 1));
			$hostname = $tmp;
		}

		if (!$val) {
			\Error_Reporter::silence(fn() => $this->web_remove_ssl($hostname));
			// detach hostname from cert + reissue?
			return true;
		}

		if (!$this->letsencrypt_supported() && !$this->ssl_cert_exists()) {
			return error('SSL not enabled on account');
		}
		$certdata = $this->ssl_get_certificates();
		$cnames = [];
		if ($this->ssl_contains_cn($hostname)) {
			$this->web_set_ssl(
				$hostname,
				Mode::fromPreference(
					\Preferences::factory($this->getAuthContext())
				)->value
			);
			return true;
		}
		if ($certdata) {
			$certdata = array_pop($certdata);
			$crt = $this->ssl_get_certificate($certdata['crt']);

			if (!$this->letsencrypt_is_ca($crt)) {
				return error(':letsencrypt_nonauth', "SSL certificate provided by CA other than Let's Encrypt. " .
					"Contact issuer to add hostname `%s' to certificate or disable SSL for this web app. " .
					'New certificate must then be installed to proceed.', $hostname);
			}
		}

		$cnames[] = $hostname;
		if (!$this->letsencrypt_append($cnames, false)) {
			[$subdomain, $domain] = array_values($this->web_split_host($hostname));

			return error(':letsencrypt_failed',
				"Failed to request SSL certificate for `%(hostname)s'. Internal subrequest failed. Possible causes: \n" .
				"(1) DNS invalid. Expected IP address `%(ip-expected)s' for %(hostname)s. Actual IP address `%(ip-actual)s'. \n" .
				"(2) Nameservers invalid. Expected nameserver settings `%(ns-expected)s'. Actual nameserver settings `%(ns-actual)s'. \n" .
				'(3) DNS propagation delays. If this domain was recently added, it may be a propagation delay that ' .
				'can take up to 24 hours to resolve; see %(kb)s for additional details. ' . "\n\n" .
				'Try again later or disable SSL.',
				[
					'hostname'    => $hostname,
					'ip-expected' => $this->dns_get_public_ip(),
					'ip-actual'   => (string)$this->dns_gethostbyname_t($hostname),
					'ns-expected' => implode(', ', $this->dns_get_hosting_nameservers($domain)),
					'ns-actual'   => implode(', ', (array)$this->dns_get_authns_from_host($hostname)),
					'kb'          => MISC_KB_BASE . '/dns/dns-work'
				]
			);
		}

		return $this->web_set_ssl(
			$hostname,
			Mode::fromPreference(
				\Preferences::factory($this->getAuthContext())
			)->value
		);
	}


}