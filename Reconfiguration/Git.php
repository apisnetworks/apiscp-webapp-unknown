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
	use Module\Support\Webapps\MetaManager;

	class Git extends Reconfigurator implements ReconfigurableProperty
	{
		public function handle(&$val): bool
		{

			$approot = $this->app->getAppRoot();
			$docroot = $this->app->getDocumentMetaPath();

			$git = \Module\Support\Webapps\Git::instantiateContexted(
				$this->getAuthContext(), [
					$approot,
					MetaManager::factory($this->getAuthContext())->get($docroot)
				]
			);
			if (!$val) {
				return $git->remove();
			}

			return $git->createRepository() && $git->snapshot(_('Initial install'));

		}
	}