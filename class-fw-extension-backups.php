<?php if (!defined('FW')) die('Forbidden');

class FW_Extension_Backups extends FW_Extension {
	/**
	 * @var _FW_Ext_Backups_Module_Tasks
	 */
	private $tasks;

	/**
	 * @return _FW_Ext_Backups_Module_Tasks
	 */
	public function tasks() {
		return $this->tasks;
	}

	/**
	 * @var _FW_Ext_Backups_Module_Schedule
	 */
	private $schedule;

	/**
	 * @return _FW_Ext_Backups_Module_Schedule
	 */
	public function schedule() {
		return $this->schedule;
	}

	private static $wp_ajax_action_status  = 'fw:ext:backups:status';
	private static $wp_ajax_action_backup  = 'fw:ext:backups:backup';
	private static $wp_ajax_action_restore = 'fw:ext:backups:restore';
	private static $wp_ajax_action_delete  = 'fw:ext:backups:delete';
	private static $wp_ajax_action_cancel  = 'fw:ext:backups:cancel';
	private static $wp_ajax_action_options = 'fw:ext:backups:save-options';
	private static $wp_ajax_action_upload  = 'fw:ext:backups:upload';

	private static $wp_ajax_action_test    = 'fw:ext:backups:test';

	/**
	 * WP options used by the selective-backup and auto-cleanup features.
	 * @since 2.0.41
	 */
	private static $wp_option_excluded_dirs = 'fw:ext:backups:excluded_dirs';
	private static $wp_option_keep_last     = 'fw:ext:backups:keep_last';

	private static $download_GET_parameter = 'download-archive';

	/**
	 * Also can be used as "is current user allowed to make backups?"
	 * @return string
	 */
	public function get_capability() {
		/**
		 * https://codex.wordpress.org/Roles_and_Capabilities#Capability_vs._Role_Table
		 * Should work on both single and multi-site
		 */
		return 'export';
	}

	/**
	 * @param int $sum Since 2.0.16
	 * @return int
	 */
	public function get_timeout($sum = 0) {
		$timeout = (int)ini_get('max_execution_time');

		/**
		 * Fix timeout value
		 * For e.g. timeout 0 messes up the tasks execution verification logic
		 */
		if ($timeout < 1 || $timeout > $this->get_config('max_timeout')) {
			$timeout = $this->get_config('max_timeout');
		}

		return max($timeout + $sum, 1); // Prevent negative or 0 value
	}

	/**
	 * If a task step execution takes more that this amount of second, then perhaps it is something wrong.
	 * @return int
	 * @since 2.0.14
	 */
	public function get_task_step_execution_threshold() {
		return 30; // http://php.net/manual/en/info.configuration.php#ini.max-execution-time
	}

	public function get_page_slug() {
		return 'fw-backups';
	}

	public function get_page_url() {
		if ($this->is_disabled()) {
			return;
		}

		$rel_path = 'admin.php?page=' . urlencode( $this->get_page_slug() );

		if (is_multisite() && is_network_admin()) {
			return network_admin_url( $rel_path );
		} else {
			return admin_url( $rel_path );
		}
	}

	/**
	 * On some installations the backup actions need to be disabled for security reasons
	 * (for e.g. public testlabs for clients to test your theme and demo content install)
	 * @return bool
	 * @since 2.0.1
	 */
	public function is_disabled() {
		$cache_key = $this->get_cache_key('/disabled');

		try {
			return FW_Cache::get($cache_key);
		} catch (FW_Cache_Not_Found_Exception $e) {
			$is_disabled = (
				is_multisite() && !current_user_can('manage_network_plugins') &&
				apply_filters('fw:ext:backups:multisite_disabled', false)
			);

			FW_Cache::set($cache_key, $is_disabled);

			return $is_disabled;
		}
	}

	/**
	 * @since 2.0.22
	 * @return string Error message
	 */
	public function server_requirements_not_met() {
		if (class_exists('ZipArchive')) {
			return false;
		} else {
			return sprintf(
				__('Oops, %s requires %s but it is not enabled on your server. If you are not familiar with PHP Zip module, please contact your hosting provider.', 'fw'),
				fw_html_tag('a', array(
					'href' => function_exists('menu_page_url')
						? menu_page_url(fw()->extensions->manager->get_page_slug(), false) .'#ext-backups'
						: '#',
				), __('Unyson+ Backups', 'fw')),
				fw_html_tag('a', array(
					'href' => 'https://www.google.com/search#q=hosting+enable+php+zip',
					'target' => '_blank',
				), __('PHP Zip module', 'fw'))
			);
		}
	}

	protected function _init() {

		if ( is_admin() && isset( $_SERVER['SERVER_SOFTWARE'] ) && strpos( $_SERVER['SERVER_SOFTWARE'], 'LiteSpeed' ) !== false ) {
			if ( ! is_file( ABSPATH . '.htaccess' ) || ! preg_match( '/noabort/i', file_get_contents( ABSPATH . '.htaccess' ) ) ) {
				add_action( 'admin_notices', array( $this, '_action_admin_notices_litespeed' ) );
			}
		}

		{
			if (!$this->is_disabled()) {
				add_action('admin_menu', array($this, '_action_admin_menu'));
				add_action('current_screen',  array($this, '_action_download'));
				add_action('admin_enqueue_scripts', array($this, '_action_enqueue_scripts'));

				add_action('wp_ajax_' . self::$wp_ajax_action_status,  array($this, '_action_ajax_status'));
				add_action('wp_ajax_' . self::$wp_ajax_action_backup,  array($this, '_action_ajax_backup'));
				add_action('wp_ajax_' . self::$wp_ajax_action_restore, array($this, '_action_ajax_restore'));
				add_action('wp_ajax_' . self::$wp_ajax_action_delete,  array($this, '_action_ajax_delete'));
				add_action('wp_ajax_' . self::$wp_ajax_action_cancel,  array($this, '_action_ajax_cancel'));
				add_action('wp_ajax_' . self::$wp_ajax_action_options, array($this, '_action_ajax_save_options'));
				add_action('wp_ajax_' . self::$wp_ajax_action_upload,  array($this, '_action_ajax_upload'));
			}

			add_action('network_admin_menu', array($this, '_action_admin_menu'));
			add_action('wp_ajax_nopriv_' . self::$wp_ajax_action_test,  array($this, '_action_ajax_test'));

			/**
			 * Auto-cleanup: after a backup zip is created, prune old archives
			 * down to the configured "keep last N" limit.
			 * @since 2.0.41
			 */
			add_action('fw:ext:backups:task:success', array($this, '_action_cleanup_old_archives'));
		}

		$dir = dirname(__FILE__);

		// load and init modules/parts
		{
			require_once $dir .'/includes/module/class--fw-ext-backups-module.php';

			require_once $dir .'/includes/module/tasks/class--fw-ext-backups-module-tasks.php';
			$this->tasks = new _FW_Ext_Backups_Module_Tasks(self::get_access_key());

			require_once $dir .'/includes/module/schedule/class--fw-ext-backups-module-schedule.php';
			$this->schedule = new _FW_Ext_Backups_Module_Schedule(self::get_access_key());

			$this->tasks->_init();
			$this->schedule->_init();
		}

		require_once $dir .'/includes/log/init.php';
	}

	public function _action_admin_notices_litespeed() {

		$screen = get_current_screen();

		if ( ! $this->is_backups_page() && 'tools_page_fw-backups-demo-content' !== $screen->id ) {
			return;
		}

		echo
			'<div class="notice notice-warning">
				<p><strong>Unyson+: </strong>' .
					sprintf(
						esc_html__( 'Your website is hosted using the LiteSpeed web server. Please consult this %sarticle%s if you have problems backing up.', 'fw' ),
						'<a href="https://unysonplus.github.io/docs/extensions/backups#litespeed-web-server" target="_blank" rel="noopener">',
						'</a>'
					) .
				'</p>
			</div>';
	}

	/**
	 * @var FW_Access_Key
	 */
	private static $access_key;

	/**
	 * @return FW_Access_Key
	 */
	private static function get_access_key() {
		if (empty(self::$access_key)) {
			self::$access_key = new FW_Access_Key('fw:ext:backups');
		}

		return self::$access_key;
	}

	/**
	 * @return bool
	 */
	public function is_backups_page() {
		$current_screen = get_current_screen();

		if (!$current_screen) {
			return false;
		}

		$cache_key = $this->get_cache_key('/is_backups_page');

		try {
			return FW_Cache::get($cache_key);
		} catch (FW_Cache_Not_Found_Exception $e) {
			$is = false;

			foreach (array( '_page_'. $this->get_page_slug(), '_page_'. $this->get_page_slug() .'-network' ) as $suffix) {
				if (substr($current_screen->id, -strlen($suffix)) === $suffix) {
					$is = true;
					break;
				}
			}

			FW_Cache::set($cache_key, $is);

			return $is;
		}
	}

	public function _action_enqueue_scripts() {
		if ($this->is_backups_page()) {
			wp_enqueue_style('fw');
			wp_enqueue_media(); // needed for modal styles

			wp_enqueue_style(
				'fw-ext-backups',
				$this->get_uri('/static/style.css'),
				array('fw'),
				$this->manifest->get_version()
			);

			wp_enqueue_script(
				'fw-ext-backups',
				$this->get_uri('/static/scripts.js'),
				array('fw'),
				$this->manifest->get_version()
			);
			wp_localize_script(
				'fw-ext-backups',
				'_fw_ext_backups_localized',
				array_merge(
					apply_filters('fw:ext:backups:script_localized_data', array()),
					array(
						'ajax_action_status'  => self::$wp_ajax_action_status,
						'ajax_action_backup'  => self::$wp_ajax_action_backup,
						'ajax_action_restore' => self::$wp_ajax_action_restore,
						'ajax_action_delete'  => self::$wp_ajax_action_delete,
						'ajax_action_cancel'  => self::$wp_ajax_action_cancel,
						'ajax_action_options' => self::$wp_ajax_action_options,
						'ajax_action_upload'  => self::$wp_ajax_action_upload,
						'options_nonce'       => wp_create_nonce(self::$wp_ajax_action_options),
						'upload_nonce'        => wp_create_nonce(self::$wp_ajax_action_upload),
						'l10n' => array(
							'abort_confirm'   => __('Are you sure?', 'fw'),
							'options_saved'   => __('Selection saved.', 'fw'),
							'upload_no_file'  => __('Please choose a .zip backup file first.', 'fw'),
							'upload_done'     => __('Backup uploaded.', 'fw'),
						),
					)
				)
			);

			do_action('fw:ext:backups:enqueue_scripts');
		}
	}

	/**
	 * @internal
	 */
	public function _action_ajax_status() {
		if (!current_user_can($this->get_capability())) {
			wp_send_json_error(new WP_Error('denied', 'Access Denied'));
		}

		// in case the execution chain stopped and there is a pending task
		$this->tasks()->_request_next_step_execution(self::get_access_key());

		$is_busy = (bool)$this->tasks()->get_active_task_collection();
		$archives = $this->get_archives();

		$response = array(
			'is_busy' => $is_busy,
			'tasks_status' => array(
				'html' => $this->render_view('tasks-status', array(
					'active_task_collection' => $this->tasks()->get_active_task_collection(),
					'executing_task' => $this->tasks()->get_executing_task(),
					'pending_tasks' => $this->tasks()->get_pending_task(),
				)),
			),
			'archives' => array(
				'count' => count($archives),
				'html' => $this->render_view('archives', array(
					'archives' => $archives,
					'is_busy' => $is_busy,
				)),
			),
			'ajax_steps' => array(
				'token' => md5(
					defined('NONCE_SALT')
						? NONCE_SALT
						: $this->manifest->get_version()
				),
				'active_tasks_hash' => (($collection = $this->tasks()->get_active_task_collection())
					? md5(serialize($collection))
					: ''
				)
			),
		);

		wp_send_json_success(array_merge(
			apply_filters('fw_ext_backups_ajax_status_extra_response', array(), array('is_busy' => $is_busy)),
			$response
		));
	}

	/**
	 * @internal
	 */
	public function _action_ajax_backup() {
		if (!current_user_can($this->get_capability())) {
			wp_send_json_error(new WP_Error('denied', 'Access Denied'));
		}

		$this->tasks()->do_backup(
			!empty($_POST['full'])
			&&
			fw_ext_backups_current_user_can_full()
		);

		wp_send_json_success();
	}

	/**
	 * @internal
	 */
	public function _action_ajax_restore() {
		if (!current_user_can($this->get_capability())) {
			wp_send_json_error(new WP_Error('denied', 'Access Denied'));
		}

		$archives = $this->get_archives();

		if (
			empty($_POST['file'])
			||
			!isset($archives[ $filename = (string)$_POST['file'] ])
		) {
			wp_send_json_error(new WP_Error(
				'no_file', __('File not specified', 'fw')
			));
		}

		$fs_args = array();

		if ($archives[ $filename ]['full'] && !FW_WP_Filesystem::has_direct_access(ABSPATH)) {
			if (empty($_POST['filesystem_args'])) {
				wp_send_json_error(array(
					'message' => esc_html__('Filesystem access required', 'fw'),
					'request_fs' => true,
				));
			} else {
				$fs_args = $_POST['filesystem_args'];

				if (
					is_array($_POST['filesystem_args']) &&
					isset($fs_args['hostname']) && is_string($fs_args['hostname']) &&
					isset($fs_args['username']) && is_string($fs_args['username']) &&
					isset($fs_args['password']) && is_string($fs_args['password']) &&
					isset($fs_args['connection_type']) && is_string($fs_args['connection_type'])
				) {
					$fs_args = array(
						'hostname' => $fs_args['hostname'],
						'username' => $fs_args['username'],
						'password' => $fs_args['password'],
						'connection_type' => $fs_args['connection_type'],
					);

					if (!WP_Filesystem($fs_args, ABSPATH)) {
						wp_send_json_error(array(
							'message' => esc_html__('Invalid filesystem credentials', 'fw')
						));
					}
				} else {
					wp_send_json_error(array(
						'message' => esc_html__('Invalid filesystem credentials', 'fw')
					));
				}
			}
		}

		$this->tasks()->do_restore(
			$archives[ $filename ]['full'] && fw_ext_backups_current_user_can_full(),
			$archives[ $filename ]['path'],
			$fs_args
		);

		wp_send_json_success();
	}

	/**
	 * @internal
	 */
	public function _action_ajax_delete() {
		if (!current_user_can($this->get_capability())) {
			wp_send_json_error(new WP_Error('denied', 'Access Denied'));
		}

		$archives = $this->get_archives();

		if (
			empty($_POST['file'])
			||
			!isset($archives[ $filename = (string)$_POST['file'] ])
		) {
			wp_send_json_error(new WP_Error(
				'no_file', __('File not specified', 'fw')
			));
		}

		if (@unlink($archives[ $filename ]['path'])) {
			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	/**
	 * @internal
	 */
	public function _action_ajax_cancel() {
		if (!current_user_can($this->get_capability())) {
			wp_send_json_error(new WP_Error('denied', 'Access Denied'));
		}

		if ($this->tasks()->do_cancel()) {
			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	/**
	 * @internal
	 *
	 * Register the Backup page as a submenu of the Unyson+ ("fw-extensions") menu
	 * instead of the WordPress "Tools" menu. The Unyson+ menu is registered by the
	 * extensions manager on both admin_menu and network_admin_menu, so the same
	 * parent slug works for single-site and network admin.
	 */
	public function _action_admin_menu() {
		add_submenu_page(
			'fw-extensions',
			__( 'Backups', 'fw' ),
			__( 'Backups', 'fw' ),
			$this->get_capability(),
			$this->get_page_slug(),
			array( $this, '_render_page' )
		);
	}

	/**
	 * @param null|bool Get only full or content backups
	 * @return array Descending date sorting
	 */
	public function get_archives($full = null) {
		$archives = array();

		if ($this->server_requirements_not_met()) {
			return $archives;
		}

		/**
		 * Scan the current backups dir and the legacy /uploads location, so
		 * archives created before backups were moved out of /uploads stay
		 * listable (and thus downloadable/deletable via their full path).
		 */
		$paths = array();
		foreach (array_unique(array(
			$this->get_backups_dir(),
			fw_ext_backups_legacy_destination_directory(),
		)) as $dir) {
			if ($found = glob($dir .'/*.zip')) {
				$paths = array_merge($paths, $found);
			}
		}

		if ($paths) {
			foreach ( $paths as $path ) {
				{
					$zip = new ZipArchive();

					if ( true === $zip->open( $path ) ) {
						$is_full = (bool) (
							$zip->locateName( 'f/themes/index.php' ) !== false
							||
							$zip->locateName( 'f/plugins/index.php' ) !== false
						);

						$zip->close();
					} else {
						trigger_error('Cannot open zip: '. $path, E_USER_WARNING);
						continue;
					}
				}

				if (
					!is_null($full)
					&&
					$full != $is_full
				) {
					continue;
				}

				$archives[ basename( $path ) ] = array(
					'path' => $path,
					'full' => $is_full,
					'time' => filemtime($path),
				);
			}
		}

		uasort($archives, array($this, '_archive_sort_callback'));

		return $archives;
	}

	public function _archive_sort_callback($a, $b) {
		if ($a['time'] == $b['time']) {
			return 0;
		} else {
			return ($a['time'] > $b['time']) ? -1 : 1;
		}
	}

	/* ---------------------------------------------------------------------
	 * Selective backup + auto-cleanup  (@since 2.0.41)
	 * ------------------------------------------------------------------- */

	/**
	 * Top-level folders the user can include/exclude from a backup, grouped by
	 * category. Plugins/themes apply to full backups; uploads to both.
	 *
	 * @return array {plugins: ['name', ...], themes: [...], uploads: [...]}
	 */
	public function get_selectable_dirs() {
		$wp_upload_dir = wp_upload_dir();

		$roots = array(
			'plugins' => WP_PLUGIN_DIR,
			'themes'  => get_theme_root(),
			'uploads' => $wp_upload_dir['basedir'],
		);

		// Internal/own folders that shouldn't be offered in the uploads column
		$uploads_skip = array(
			'fw-backup' => true, 'fw' => true, 'backup' => true, 'sites' => true,
		);

		$result = array('plugins' => array(), 'themes' => array(), 'uploads' => array());

		foreach ($roots as $cat => $root) {
			$root = fw_fix_path($root);

			if (!is_dir($root) || !($names = scandir($root))) {
				continue;
			}

			foreach ($names as $name) {
				if ($name === '.' || $name === '..' || $name[0] === '.') {
					continue;
				}
				if (!is_dir($root .'/'. $name)) {
					continue;
				}
				if ($cat === 'uploads' && isset($uploads_skip[$name])) {
					continue;
				}

				$result[$cat][] = $name;
			}

			sort($result[$cat]);
		}

		return $result;
	}

	/**
	 * @return array {plugins: {name: true}, themes: {...}, uploads: {...}}
	 */
	public function get_excluded_dirs() {
		$excluded = get_option(self::$wp_option_excluded_dirs, array());

		if (!is_array($excluded)) {
			$excluded = array();
		}

		foreach (array('plugins', 'themes', 'uploads') as $cat) {
			if (empty($excluded[$cat]) || !is_array($excluded[$cat])) {
				$excluded[$cat] = array();
			}
		}

		return $excluded;
	}

	/**
	 * @param array $excluded {plugins: {name: true}, themes: {...}, uploads: {...}}
	 */
	public function set_excluded_dirs(array $excluded) {
		$clean = array('plugins' => array(), 'themes' => array(), 'uploads' => array());

		foreach (array('plugins', 'themes', 'uploads') as $cat) {
			if (!empty($excluded[$cat]) && is_array($excluded[$cat])) {
				foreach ($excluded[$cat] as $name => $v) {
					$name = basename((string) $name); // prevent path traversal
					if ($name !== '' && $name !== '.' && $name !== '..') {
						$clean[$cat][$name] = true;
					}
				}
			}
		}

		update_option(self::$wp_option_excluded_dirs, $clean, false);
	}

	/**
	 * Number of most-recent archives to keep (0 = unlimited).
	 * @return int
	 */
	public function get_keep_last() {
		return max(0, (int) get_option(self::$wp_option_keep_last, 0));
	}

	/**
	 * @param int $n
	 */
	public function set_keep_last($n) {
		update_option(self::$wp_option_keep_last, max(0, (int) $n), false);
	}

	/**
	 * Delete oldest archives beyond the "keep last N" limit.
	 */
	public function cleanup_old_archives() {
		$keep = $this->get_keep_last();

		if ($keep < 1) {
			return; // unlimited
		}

		$archives = $this->get_archives(); // already sorted newest-first
		$i = 0;

		foreach ($archives as $archive) {
			if (++$i > $keep) {
				@unlink($archive['path']);
			}
		}
	}

	/**
	 * @internal
	 * @param FW_Ext_Backups_Task $task
	 */
	public function _action_cleanup_old_archives($task) {
		if (
			is_object($task)
			&&
			method_exists($task, 'get_type')
			&&
			$task->get_type() === 'zip'
		) {
			$this->cleanup_old_archives();
		}
	}

	/**
	 * @internal Save the selective-backup folder selection + keep-last value.
	 */
	public function _action_ajax_save_options() {
		if (!current_user_can($this->get_capability())) {
			wp_send_json_error(new WP_Error('denied', 'Access Denied'));
		}

		if (
			empty($_POST['nonce'])
			||
			!wp_verify_nonce($_POST['nonce'], self::$wp_ajax_action_options)
		) {
			wp_send_json_error(new WP_Error('invalid_nonce', __('Invalid nonce', 'fw')));
		}

		$posted = (isset($_POST['excluded']) && is_array($_POST['excluded']))
			? $_POST['excluded']
			: array();

		// Arrives as {plugins: ['akismet', ...], ...}; convert to {name: true}
		$excluded = array('plugins' => array(), 'themes' => array(), 'uploads' => array());
		foreach (array('plugins', 'themes', 'uploads') as $cat) {
			if (!empty($posted[$cat]) && is_array($posted[$cat])) {
				foreach ($posted[$cat] as $name) {
					$excluded[$cat][(string) $name] = true;
				}
			}
		}

		$this->set_excluded_dirs($excluded);
		$this->set_keep_last(isset($_POST['keep_last']) ? $_POST['keep_last'] : 0);

		wp_send_json_success();
	}

	/**
	 * @internal Store an uploaded .zip backup into the archives directory.
	 */
	public function _action_ajax_upload() {
		if (!current_user_can($this->get_capability())) {
			wp_send_json_error(new WP_Error('denied', 'Access Denied'));
		}

		if (
			empty($_POST['nonce'])
			||
			!wp_verify_nonce($_POST['nonce'], self::$wp_ajax_action_upload)
		) {
			wp_send_json_error(new WP_Error('invalid_nonce', __('Invalid nonce', 'fw')));
		}

		if (
			empty($_FILES['file'])
			||
			!isset($_FILES['file']['tmp_name'])
			||
			!is_uploaded_file($_FILES['file']['tmp_name'])
		) {
			wp_send_json_error(new WP_Error('no_file', __('No file uploaded', 'fw')));
		}

		$file = $_FILES['file'];

		if (!empty($file['error'])) {
			wp_send_json_error(new WP_Error(
				'upload_error', sprintf(__('Upload error (code %d)', 'fw'), (int) $file['error'])
			));
		}

		$filename = sanitize_file_name(basename($file['name']));

		if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
			wp_send_json_error(new WP_Error('not_zip', __('Only .zip backup files are allowed', 'fw')));
		}

		if (!class_exists('ZipArchive')) {
			wp_send_json_error(new WP_Error('zip_missing', __('PHP Zip extension is required', 'fw')));
		}

		// Validate that the zip looks like a backup made by this extension
		$zip = new ZipArchive();
		if (true !== $zip->open($file['tmp_name'])) {
			wp_send_json_error(new WP_Error('bad_zip', __('The file is not a valid zip archive', 'fw')));
		}

		$looks_like_backup = (
			$zip->locateName('database.json.txt') !== false
			||
			$zip->locateName('database.json') !== false
		);

		if (!$looks_like_backup) {
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i);
				if ($stat && strpos($stat['name'], 'f/') === 0) {
					$looks_like_backup = true;
					break;
				}
			}
		}

		$zip->close();

		if (!$looks_like_backup) {
			wp_send_json_error(new WP_Error(
				'not_backup',
				__('This zip does not look like a backup created by this extension.', 'fw')
			));
		}

		$dest_dir = $this->get_backups_dir();
		if (!is_dir($dest_dir) && !wp_mkdir_p($dest_dir)) {
			wp_send_json_error(new WP_Error('mkdir_fail', __('Cannot create backups directory', 'fw')));
		}

		$target = $dest_dir .'/'. $filename;
		if (file_exists($target)) { // don't overwrite an existing archive
			$target = $dest_dir .'/'. pathinfo($filename, PATHINFO_FILENAME) .'-'. time() .'.zip';
		}

		if (!@move_uploaded_file($file['tmp_name'], $target)) {
			wp_send_json_error(new WP_Error('move_fail', __('Failed to store the uploaded file', 'fw')));
		}

		wp_send_json_success();
	}

	/**
	 * @internal
	 */
	public function _render_page() {
		echo '<div class="wrap">';

		if ($error_message = $this->server_requirements_not_met()) {
			echo "<div class=\"notice notice-error\"><p>{$error_message}</p></div>";
		} else {
			$this->render_view( 'page', array(
				'archives_html' => $this->render_view( 'archives', array(
					'archives' => $this->get_archives(),
					'is_busy'  => (bool) $this->tasks()->get_active_task_collection(),
				) ),
			), false );
		}

		echo '</div>';

		echo '<div id="fw-ext-backups-filesystem-form" style="display:none;">';
		FW_WP_Filesystem::request_access(ABSPATH);
		echo '</div>';
	}

	/**
	 * @return string
	 */
	public function get_tmp_dir() {
		return $this->get_backups_dir() . '/tmp';
	}

	/**
	 * All backups (zip) will go in this directory
	 * @return string
	 */
	public function get_backups_dir() {
		return $this->get_config( 'dirs.destination' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function _get_link() {
		if (current_user_can($this->get_capability())) {
			return $this->get_page_url();
		} else {
			return null;
		}
	}

	/**
	 * @internal
	 */
	public function _get_test_ajax_action() {
		return self::$wp_ajax_action_test;
	}

	/**
	 * @internal
	 */
	public function _action_ajax_test() {
		wp_send_json_success();
	}

	public function get_download_link($archive_filename) {
		return add_query_arg(self::$download_GET_parameter, urlencode($archive_filename), $this->get_page_url());
	}

	public function _action_download() {
		if (
			!isset($_GET[self::$download_GET_parameter])
			||
		    !is_string($archive_filename = $_GET[self::$download_GET_parameter])
			||
		    !$this->is_backups_page()
		) {
			return;
		}

		$error = __('Unknown error', 'fw');

		do {
			if (!current_user_can($this->get_capability())) {
				$error = __('Access Denied', 'fw');
				break;
			}

			$archives = $this->get_archives();

			if (!isset($archives[$archive_filename])) {
				$error = __('Archive not found', 'fw');
				break;
			}

			$archive = $archives[$archive_filename];

			if ($archive['full'] && !fw_ext_backups_current_user_can_full()) {
				$error = __('Access Denied', 'fw');
				break;
			}

			if ($f = fopen($archive['path'], 'r')) {
				// ok
			} else {
				$error = __('Failed to open file', 'fw');
				break;
			}

			header('Content-Type: application/zip, application/octet-stream');
			header('Content-Disposition: attachment; filename="'. esc_attr($archive_filename) .'"');
			header('Content-length: '. filesize($archive['path']));
			header('Cache-control: private');

			/**
			 * Some files can be huge, do not load entire file in php memory then output, it can cause memory limit error
			 * Read and output parts
			 */
			{
				$output_buffer_size = max(
					// https://github.com/ThemeFuse/Unyson/issues/2070#issuecomment-258427852
					(int)ini_get('output_buffering'),
					// default to this value in case ini_get() will return 0 (some server restrictions)
					// http://php.net/manual/en/outcontrol.configuration.php#ini.output-buffering
					4096
				);

				while (!feof($f)) {
					echo fread($f, $output_buffer_size);
					if (ob_get_level()) { ob_flush(); }
					flush();
				}
			}

			fclose($f);

			exit;
		} while(false);

		wp_die($error, $error);
	}
}
