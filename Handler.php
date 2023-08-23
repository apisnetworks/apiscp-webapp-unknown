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
 * Written by Matt Saladna <matt@apisnetworks.com>, August 2020
 */

namespace Module\Support\Webapps\App\Type\Unknown;

	use apnscpFunctionInterceptor;
	use apnscpFunctionInterceptorTrait;
	use ArrayAccess;
	use Auth_Info_User;
	use ContextableTrait;
	use Module\Support\Webapps;
	use Module\Support\Webapps\Git;
	use Module\Support\Webapps\MetaManager;
	use NamespaceUtilitiesTrait;
	use ReflectionClass;
	use Session;
	use function in_array;
	use function is_array;


	class Handler
	{
		use NamespaceUtilitiesTrait;
		use apnscpFunctionInterceptorTrait;
		use ContextableTrait;

		const NAME = 'unknown';
		const ADMIN_PATH = '';
		const FEAT_ALLOW_SSL = false;
		const FEAT_RECOVERY = false;
		const FEAT_GIT = true;
		const DEFAULT_FORTIFICATION = 'max';
		// @var array options not saved on reconfiguration
		const TRANSIENT_RECONFIGURABLES = [
			'migrate'
		];

		protected $hostname;
		protected $docroot;
		protected $path = '';

		/**
		 * @var MetaManager\Meta application meta info
		 */
		protected $manager;
		/**
		 * @var MetaManager\Options
		 */
		protected $options;
		/**
		 * @var MetaManager\Meta
		 */
		protected $meta;
		/**
		 * @var string module mapping
		 */
		protected $mapping;
		/**
		 * @var Adhoc\Manifest
		 */
		protected $manifest;
		/**
		 * @var Webapps\App\UIPanel\Element
		 */
		protected $pane;

		/**
		 * Create new app type for docroot
		 *
		 * @param string|null         $docroot
		 * @param Auth_Info_User|null $ctx
		 * @return static
		 */
		public static function factory(?string $docroot, Auth_Info_User $ctx)
		{
			return static::instantiateContexted($ctx, [$docroot]);
		}

		public function __construct(?string $docroot)
		{
			$this->docroot = $docroot;
			if ($docroot === null) {
				return;
			}

			$this->manager = MetaManager::factory($this->getAuthContext());

			// MetaManager takes care of symlinks
			$this->meta = $this->manager->get($docroot);
			// @todo warn if $docroot irresolvable?
			$docroot = $docroot ?: \a23r::get_class_from_module('web')::MAIN_DOC_ROOT;

			if (null === ($components = $this->web_extract_components_from_path($docroot))) {
				// path is symlinked, use domain/path as established in UI
				$components = ['host' => $this->meta['hostname'], 'path' => ($this->meta['path'] ?? '')];
				if (empty($components['host'])) {
					fatal("Cannot determine hostname from filesystem path `%s' - is this reachable by web?", $docroot);
				}
			}
			if (empty($this->meta['hostname']) || (string)($this->meta['path'] ?? '') !== $components['path']) {
				$this->meta->replace($components);
			}

			if (empty($this->meta['hostname'])) {
				report("Empty hostname %s", var_export($this->meta, true));
			}
			$this->setHostname($this->meta['hostname']);
			$this->setPath($this->meta['path'] ?? '');
			$stat = $this->file_stat($this->getAppRoot());
			$this->options = $this->meta->getOptions();
			if (!isset($this->options['user'])) {
				$this->options['user'] = $stat['owner'] ?? Session::get('username');
			}
			$this->manifest = Webapps\App\Type\Adhoc\Manifest::instantiateContexted(
				$this->getAuthContext(),
				[$this]
			);

			if ($this->manifest->exists()) {
				$base = $this->getManifest()['base'];
				if (null !== $base && !\in_array($base, Webapps::knownApps(), true)) {
					warn("Unknown app type `%s', ignoring manifest override", $base);
					$base = null;
				}
				$this->mapping = $base;
			}
		}

		/**
		 * Text representation of app
		 *
		 * @return string
		 */
		public function __toString(): string
		{
			return (string)strtolower($this->getName());
		}

		/**
		 * Set app internal configuration
		 *
		 * @param string|array $name
		 * @param mixed|null   $value
		 * @return self
		 */
		public function initializeMeta($name, $value = null): self
		{
			if (is_array($name) && null !== $value) {
				fatal('setMap() takes either an array of values or a single name with separate value parameter');
			}

			$this->meta->replace(
				is_array($name) ? $name : [$name => $value]
			);
			// @xxx must be called to avoid class@anonymous serialization error
			$this->meta->sync();
			return $this;
		}

		/**
		 * Application display name
		 */
		public function getName(): string
		{
			return static::NAME;
		}

		/**
		 * Handle discretionary options
		 *
		 * @param array $params
		 * @return bool
		 */
		public function handle(array $params): bool
		{
			foreach ($params as $p => $val) {
				if ($this->hasReconfigurable($p)) {
					return $this->reconfigure([$p => $val]);
				}
			}
			return error('Unknown settings');
		}

		/**
		 * Get application type (css)
		 *
		 * @return string
		 */
		public function getModuleName(): string
		{
			$name = $this->getHandlerName();

			return $name === 'unknown' ? 'webapp' : $name;
		}

		/**
		 * Get handler name
		 *
		 * @return string
		 */
		public function getHandlerName(): string
		{
			return strtolower(basename(strtr(static::getNamespace(\get_class($this)), '\\', '/')));
		}

		/**
		 * Get webapp meta
		 *
		 * @param $docroot
		 * @return mixed
		 */
		public function getApplicationInfo($docroot = null): array
		{
			if (null === $docroot) {
				$docroot = $this->getDocumentMetaPath();
			}

			return $this->manager->get($docroot)->toArray();
		}

		/**
		 * Get document path for meta
		 *
		 * @return string
		 */
		public function getDocumentMetaPath(): string
		{
			$stat = $this->file_stat($this->getDocumentRoot());
			return $stat['referent'] ?: $this->docroot;
		}

		/**
		 * Get document root
		 *
		 * @return string
		 */
		public function getDocumentRoot(): string
		{
			// web_normalize_path can fail if the doc root + parent are gone
			// give it an opportunity to remove itself correctly
			return $this->docroot ?? $this->web_normalize_path($this->getHostname(), $this->getPath());
		}

		/**
		 * Get hostname
		 *
		 * @return string
		 */
		public function getHostname(): string
		{
			return $this->hostname;
		}

		/**
		 * Set app hostname
		 *
		 * @param $hostname
		 * @return self
		 */
		public function setHostname(string $hostname): self
		{
			$this->hostname = $hostname;

			return $this;
		}

		/**
		 * Get URI path
		 *
		 * @return string
		 */
		public function getPath(): string
		{
			return $this->path;
		}

		/**
		 * Set URI path
		 *
		 * @param string $path
		 * @return self
		 */
		public function setPath(string $path = ''): self
		{
			$this->path = $path;

			return $this;
		}

		/**
		 * Load git instance
		 *
		 * @return Webapps\Git
		 */
		public function git(): Webapps\Git
		{
			return Webapps\Git::instantiateContexted($this->getAuthContext(), [
				$this->getAppRoot(),
				MetaManager::instantiateContexted($this->getAuthContext())->get($this->getDocumentMetaPath())
			]);
		}

		/**
		 * Get application root
		 *
		 * @return string
		 */
		public function getAppRoot(): ?string
		{
			return $this->docroot;
		}

		/**
		 * Get application meta
		 *
		 * @return MetaManager\Meta
		 */
		public function getAppMeta(): MetaManager\Meta
		{
			return $this->meta;
		}

		/**
		 * Versions that may be installed from frontend
		 *
		 * @return array
		 */
		public function getInstallableVersions(): array
		{
			return $this->getVersions();
		}

		/**
		 * Application versions
		 *
		 * @return array
		 */
		public function getVersions(): array
		{
			return $this->{$this->getClassMapping() . '_get_versions'}();
		}

		/**
		 * \PHP class, <NAME>_Module
		 *
		 * @param bool $genericFallback replace "unknown" with "webapp"
		 * @return string
		 */
		public function getClassMapping(): string
		{
			return $this->mapping ?? $this->getModuleName();
		}

		/**
		 * Display application?
		 *
		 * @return bool
		 */
		public function display(): bool
		{
			return static::class !== self::class;
		}

		/**
		 * Fortification mode exists
		 *
		 * @param string $mode
		 * @return bool
		 */
		public function hasFortificationMode(string $mode): bool
		{
			if (($mapping = $this->getClassMapping()) === 'webapp' && !$this->hasManifest()) {
				return false;
			}
			if ($mapping === 'unknown') {
				$mapping = 'webapp';
			}

			return $this->{$mapping . '_has_fortification'}($this->getHostname(), $this->getPath(), $mode);
		}

		/**
		 * Get available Fortification modes
		 *
		 * @return array
		 */
		public function fortificationModes(): array
		{
			if (($mapping = $this->getClassMapping()) === 'webapp' && !$this->hasManifest()) {
				return [];
			}
			if ($mapping === 'unknown') {
				$mapping = 'webapp';
			}

			return (array)($this->{$mapping . '_fortification_modes'}($this->getHostname(), $this->getPath()));
		}

		/**
		 * Application has manifest support
		 *
		 * @return bool
		 */
		public function hasManifest(): bool
		{
			return $this->getManifest()->exists();
		}

		/**
		 * Get Manifest handler
		 *
		 * @return Adhoc\Manifest
		 */
		public function getManifest(): Webapps\App\Type\Adhoc\Manifest
		{
			return $this->manifest;
		}

		/**
		 * Module has feature implemented
		 *
		 * @param string $func function name
		 * @return bool
		 */
		protected function moduleHas(string $func): bool
		{
			$module = $this->reflectModule($this->getClassMapping());
			if ($module->getName() === \Webapp_Module::class) {
				return false;
			}
			return $module->hasMethod($func) &&
				$module->getMethod($func)->getDeclaringClass()->getName() === $module->getName() && !$module->isAbstract();
		}

		/**
		 * Web App has reconfigurable property
		 *
		 * @param string $property
		 * @return bool
		 */
		public function hasReconfigurable(string $property): bool
		{
			static $cache;
			if (null === $cache) {
				$cache = $this->getReconfigurables();
			}

			return in_array($property, $cache, true);
		}

		/**
		 * Get webapp reconfigurable
		 * @param string $property
		 * @return mixed
		 */
		public function getReconfigurable(string $property)
		{
			if (!$this->hasReconfigurable($property)) {
				return null;
			}
			if ('unknown' === ($mapping = $this->getClassMapping())) {
				$mapping = 'webapp';
			}
			return $this->{"${mapping}_get_reconfigurable"}($this->getHostname(), $this->getPath(), $property);
		}

		/**
		 * Reflect module for inspection
		 *
		 * @param string $module
		 * @return null|ReflectionClass
		 */
		protected function reflectModule(string $module): ?ReflectionClass
		{
			$class = apnscpFunctionInterceptor::get_class_from_module($module);
			if (!class_exists($class)) {
				return null;
			}

			return new ReflectionClass($class);
		}

		/**
		 * Get administrative path
		 *
		 * @return string
		 */
		public function getAdminPath(): ?string
		{
			return static::ADMIN_PATH;
		}

		/**
		 * App has recovery feature
		 *
		 * @return bool
		 */
		public function hasRecovery(): bool
		{
			return static::FEAT_RECOVERY;
		}

		/**
		 * Attempt recovery
		 *
		 * @return bool
		 */
		public function recover(): bool
		{
			return false;
		}

		/**
		 * App can change password
		 *
		 * @return bool
		 */
		public function hasChangePassword(): bool
		{
			return $this->moduleHas('change_admin');
		}

		/**
		 * Handle generic + module reconfigurables
		 *
		 * @param array $params
		 * @return bool
		 */
		public function reconfigure(array $params): bool
		{
			$ret = $this->{$this->getClassMapping() . '_reconfigure'}(
				$this->getHostname(),
				$this->getPath(),
				$params
			);
			gc_collect_cycles();

			return $ret;
		}

		/**
		 * Has reconfiguration
		 *
		 * @return bool
		 */
		public function hasReconfiguration(): bool
		{
			return true;
		}

		/**
		 * Settings that may be reconfigured
		 *
		 * @return array
		 */
		public function getReconfigurables(): array
		{
			return $this->{$this->getClassMapping() . '_reconfigurables'}(
				$this->getHostname(),
				$this->getPath()
			);
		}

		/**
		 * Allow SSL
		 *
		 * @return bool
		 */
		public function allowSsl(): bool
		{
			return static::FEAT_ALLOW_SSL;
		}

		/**
		 * Allow git backing
		 *
		 * @return bool
		 */
		public function allowGit(): bool
		{
			return static::FEAT_GIT;
		}

		/**
		 * App has git setup
		 *
		 * @return bool
		 */
		public function hasGit(): bool
		{
			return $this->git_valid($this->getAppRoot());
		}

		/**
		 * Get administrative user
		 *
		 * @return string
		 */
		public function getAdminUser(): string
		{
			if (!$this->hasAdmin() || !($func = $this->getClassMapping())) {
				return Session::get('username');
			}
			$func .= '_get_admin';

			return $this->{$func}($this->getHostname(), $this->getPath());
		}

		/**
		 * App has administrative user feature
		 *
		 * @return bool
		 */
		public function hasAdmin(): bool
		{
			return $this->moduleHas('get_admin');
		}

		/**
		 * Detect web app type
		 *
		 * @param string $mixed hostname or docroot
		 * @param string $path
		 * @return bool
		 */
		public function detect(string $mixed, string $path = ''): bool
		{
			$func = $this->getClassMapping();

			return $this->{$func . '_valid'}($mixed, $path);
		}

		/**
		 * App can be detected
		 *
		 * @return bool
		 */
		public function hasDetection(): bool
		{
			return $this->moduleHas('valid');
		}

		/**
		 * Install specified app
		 *
		 * @return bool|mixed
		 */
		public function install(): bool
		{
			if (!$this->hasInstall() || !($func = $this->getClassMapping())) {
				return false;
			}
			$func .= '_install';

			// Horrible hack. We need to flush docroot cache to ensure we're not referencing
			// a relinked, then cleared docroot. If Ghost (install) => Ghost (uninstall) => Wordpress (install)
			// occurs in serial, then WordPress installs into /var/www/html-ghost instead of /var/www/html
			//
			// Confusion arises when Horizon's job runner holds onto a different cache than
			// frontend or backend Web_Module::$pathCache
			$this->web_purge();

			return $this->{$func}($this->getHostname(), $this->getPath(), $this->getOptions()->toArray());
		}

		/**
		 * App has install feature
		 *
		 * @return bool
		 */
		public function hasInstall(): bool
		{
			return $this->moduleHas('install');
		}

		/**
		 * Get configured options for application
		 *
		 * @return mixed
		 */
		public function getOptions(): MetaManager\Options
		{
			return $this->meta->getOptions();
		}

		/**
		 * Set options
		 *
		 * @param array|null $options
		 * @return $this
		 */
		public function setOptions(?array $options): self
		{
			if (null !== $options) {
				$options = array_diff_key($options, static::TRANSIENT_RECONFIGURABLES);
			}
			$this->meta->setOption($options);

			return $this;
		}

		/**
		 * Uninstall app
		 *
		 * @return bool
		 */
		public function uninstall(): bool
		{
			if (!$this->hasUninstall() || ($func = $this->getClassMapping()) === 'webapp') {
				return false;
			}
			$func .= '_uninstall';
			$options = $this->getOptions();
			$uninstall = $options['uninstall'] ?? 'all';
			$this->web_purge();

			return $this->{$func}($this->getHostname(), $this->getPath(), $uninstall);
		}

		/**
		 * Has uninstall feature
		 *
		 * @return bool
		 */
		public function hasUninstall(): bool
		{
			return $this->getClassMapping() !== 'webapp';
		}

		/**
		 * Update application
		 *
		 * @param string|null $version
		 * @return bool
		 */
		public function update(string $version = null): bool
		{
			if (!$this->hasUpdate() || !($func = $this->getClassMapping())) {
				return false;
			}

			$update = Webapps\UpdateCandidate::instantiateContexted($this->getAuthContext(),
				[$this->getHostname(), $this->getPath()]);
			$update->setVersion($this->getVersion(true));
			$update->parseAppInformation($this->meta);
			if ($version) {
				$update->forceUpdateVersion($version);
			} else {
				$update->setAvailableVersions($this->getVersions());
			}

			$gitHandler = Git::instantiateContexted($this->getAuthContext(), [
				$this->getAppRoot(),
				$this->meta
			]);

			if ($gitHandler->enabled()) {
				$update->enableAssuranceMode(true);
				$update->initializeAssurance($gitHandler);
			}

			return $update->process();
		}

		/**
		 * Has update feature
		 *
		 * @return bool
		 */
		public function hasUpdate()
		{
			return $this->moduleHas('update_all');
		}

		/**
		 * Get app version
		 *
		 * @param bool $force force detection
		 * @return ?string
		 */
		public function getVersion(bool $force = false): ?string
		{
			if (!$this->hasVersion() || ($func = $this->getClassMapping()) === 'webapp') {
				return null;
			}

			$func .= '_get_version';
			if ($force || !isset($this->meta['version'])) {
				$this->meta['version'] = $this->{$func}($this->getHostname(), $this->getPath());
			}

			return $this->meta['version'];
		}

		/**
		 * App has versioning
		 *
		 * @return bool
		 */
		public function hasVersion(): bool
		{
			return $this->moduleHas('get_versions');
		}

		/**
		 * Fortify webapp
		 *
		 * @param bool|string $mode mode
		 * @param array  $args
		 * @return bool
		 */
		public function fortify($mode = null, array $args = []): bool
		{
			if (null === $mode) {
				$mode = static::DEFAULT_FORTIFICATION;
			} else if ($mode === false) {
				return $this->releaseFortify();
			}
			$func = $this->getClassMapping();
			if ($func === 'unknown') {
				$func = 'webapp';
			}

			return $this->{$func . '_fortify'}($this->getHostname(), $this->getPath(), $mode, $args);
		}

		/**
		 * Release fortification
		 *
		 * @return mixed
		 */
		public function releaseFortify()
		{
			if ('unknown' === ($mapping = $this->getClassMapping())) {
				$mapping = 'webapp';
			}

			return $this->{$mapping . '_unfortify'}($this->getHostname(), $this->getPath());
		}

		/**
		 * Has Fortification feature
		 *
		 * @return bool
		 */
		public function hasFortification(): bool
		{
			if (($mapping = $this->getClassMapping()) === 'webapp' && !$this->hasManifest()) {
				return false;
			}
			if ($mapping === 'unknown') {
				$mapping = 'webapp';
			}
			return !empty($this->{$mapping . '_fortification_modes'}($this->getHostname(), $this->getPath()));
		}

		/**
		 * Reload Web App instance
		 *
		 * @return $this
		 */
		public function reload(): self
		{
			$this->docroot = null;
			$this->__construct($this->getDocumentMetaPath());

			return $this;
		}

		/**
		 * Verify is app needs update
		 *
		 * @param string $version
		 * @return bool
		 */
		public function needsUpdate(string $version): bool
		{
			return !$this->versionStatus($version);
		}

		/**
		 * Check version status
		 *
		 * Will return false if version locked if a newer version is available
		 *
		 * @param string $version
		 * @return null|string|bool
		 */
		public function versionStatus(?string $version)
		{
			if (!$this->getClassMapping()) {
				return false;
			}
			if ($this->isInstalling()) {
				return Webapps\App\Installer::INSTALLING_VERSION;
			}

			$current = $this->{$this->getClassMapping() . '_is_current'}($version);
			if (!$current) {
				return false;
			}

			return in_array($this->getVersionLock(), ['minor', 'major'], true) ? null : true;
		}

		/**
		 * App is installing
		 *
		 * @return bool
		 */
		public function isInstalling(): bool
		{
			return isset($this->meta['version']) && $this->meta['version'] === Webapps\App\Installer::INSTALLING_VERSION;
		}

		/**
		 * Get version lock value
		 *
		 * @return array|ArrayAccess|mixed
		 */
		public function getVersionLock()
		{
			$default = apnscpFunctionInterceptor::get_autoload_class_from_module($this->getClassMapping())::DEFAULT_VERSION_LOCK;

			return array_get($this->getOptions(), 'verlock', $default === 'none' ? null : $default);
		}

		/**
		 * Application installed
		 *
		 * @return bool
		 */
		public function applicationPresent(): bool
		{
			return static::class !== self::class && $this->{$this->getClassMapping() . '_valid'}(
					$this->getHostname(),
					$this->getPath()
				);
		}

		/**
		 * Clear update failure
		 *
		 * @return self
		 */
		public function clearFailed(): self
		{
			$this->meta->replace(['failed' => false]);

			return $this;
		}

		/**
		 * Web App affixed in UI view
		 *
		 * @return bool
		 */
		public function affixed(): bool
		{
			return $this->getOption('affixed', false);
		}

		/**
		 * Get option
		 *
		 * @param string $name
		 * @param null   $default
		 * @return mixed
		 */
		public function getOption(string $name, $default = null)
		{
			return data_get($this->getOptions(), $name, $default);
		}

		/**
		 * Set affix property
		 *
		 * @param bool $val
		 * @return self
		 */
		public function setAffixed(bool $val): self
		{
			$this->reconfigure(['affixed' => $val]);

			return $this;
		}

		/**
		 * Set app option
		 *
		 * @param string|array $opt
		 * @param mixed        $val
		 * @return bool
		 */
		public function setOption($opt, $val = null): bool
		{
			// ignore
			if (\in_array($opt, static::TRANSIENT_RECONFIGURABLES, true)) {
				return true;
			}

			$this->meta->setOption(
				is_array($opt) ? $opt : [$opt => $val]
			);
			$this->meta->sync();

			return true;
		}

		/**
		 * Web App has failed last update
		 *
		 * @return bool
		 */
		public function failed(): bool
		{
			return !empty($this->meta['failed']);
		}

		/**
		 * Change admin password
		 *
		 * @param string $password
		 * @return bool
		 */
		public function changePassword(string $password): bool
		{
			return error("App type `%s' does not support password change", $this->getName());
		}

		/**
		 * Get webapp family
		 *
		 * @return string
		 */
		public function getAppFamily(): ?string
		{
			if (static::class === self::class) {
				return null;
			}
			$parent = substr($tmp = get_parent_class($this), 0, strrpos($tmp, '\\'));

			return strtolower(substr($parent, strrpos($parent, '\\') + 1));
		}

		/**
		 * Get web user for given docroot
		 *
		 * @return string
		 */
		public function getWebUser(): string
		{
			return $this->web_get_user($this->getHostname(), $this->getPath());
		}

		/**
		 * Get panel UI instance
		 *
		 * @return Webapps\App\UIPanel\Element
		 */
		public function getPane(): Webapps\App\UIPanel\Element
		{
			if (null !== $this->pane) {
				return $this->pane;
			}
			return $this->pane = Webapps\App\UIPanel\Element::instantiateContexted(
				$this->getAuthContext(), [
					Webapps\App\UIPanel::instantiateContexted($this->getAuthContext()),
					$this->getHostname(),
					$this->getPath()
			]);
		}

		/**
		 * Certificate contains hostname
		 *
		 * @return bool
		 */
		public function sslPresent(): bool
		{
			$hostname = $this->getHostname();
			if (false === strpos($hostname, '.')) {
				$hostname .= '.' . $this->getAuthContext()->domain;
			}

			return $this->ssl_contains_cn($hostname);
		}
	}