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

	/**
	 * Change domain/path for WordPress
	 *
	 * @package Module\Support\Webapps\App\Type\Wordpress\Reconfiguration
	 */
	class Migrate extends Reconfigurator implements ReconfigurableProperty
	{
		public function handle(&$val): bool
		{
			[$hostname, $path] = explode('/', $val . '/', 2);
			$path = rtrim($path, '/');

			if ($this->handler("ssl")->getValue()) {
				$sslhostname = $this->web_normalize_hostname($hostname);
				if (!$this->letsencrypt_append($sslhostname)) {
					// SSL certificate contains hostname, not a CF proxy
					return error("Failed SSL issuance");
				}
			}

			$this->app->getAppMeta()->replace([
				'hostname' => $hostname,
				'path'     => $path
			]);
			return true;
		}

		public function getValue()
		{
			return rtrim($this->app->getHostname() . '/' .  $this->app->getPath(), '/');
		}
	}