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
 * Written by Matt Saladna <matt@apisnetworks.com>, June 2024
 */

namespace Module\Support\Webapps\App\Type\Unknown\Reconfiguration;

use Module\Support\Webapps\App\Reconfigurator;
use Module\Support\Webapps\Contracts\DeferredReconfiguration;
use Module\Support\Webapps\Contracts\ReconfigurableProperty;

class Fortify extends Reconfigurator implements ReconfigurableProperty, DeferredReconfiguration
{
	const DEFAULT_MODES = ['reset', 'learn', 'write'];

	const ERR_UNKNOWN_FORTIFICATION_MODE = [
		':err_webapp_fortification_unknown',
		'Unknown fortification mode %(mode)s'
	];

	public function handle(&$val): bool
	{
		if (!in_array($val, array_merge(self::DEFAULT_MODES, $this->app->fortificationModes()), true)) {
			return error(self::ERR_UNKNOWN_FORTIFICATION_MODE);
		}

		return true;
	}

	public function apply(mixed &$val): bool
	{
		return $this->app->fortify($val);
	}
}