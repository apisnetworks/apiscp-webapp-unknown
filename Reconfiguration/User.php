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

	class User extends Reconfigurator implements ReconfigurableProperty
	{
		public function handle(&$val): bool
		{
			$hostname = $this->app->getHostname();
			$path = $this->app->getPath();

			$approot = $this->app->getAppRoot();
			$stat = $this->file_stat($approot);
			$olduser = array_get($stat, 'owner') ?: $this->getAuthContext()->username;
			if ($olduser === $val) {
				// no change
				return true;
			}

			if ($stat['file_type'] === 'link') {
				$this->file_chown_symlink($approot, $val);
			}

			$ret = $this->file_takeover_user($olduser, $val, $approot);
			if (!$path && $this->web_is_subdomain($hostname)) {
				// update subdomain symlink ownership otherwise FollowSymLinksIfOwnerMatches pukes
				$this->file_chown_symlink(\a23r::get_class_from_module('web')::SUBDOMAIN_ROOT . "/{$hostname}/html", $val);
			}

			return (bool)$ret;
		}
	}