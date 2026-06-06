<?php if (!defined('FW')) die('Forbidden');

/**
 * Delete everything from the given directory
 */
class FW_Ext_Backups_Task_Type_Dir_Clean extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'dir-clean';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __('Directory Cleanup', 'fw');
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute(array $args, array $state = array()) {
		if (!isset($args['dir'])) {
			return new WP_Error(
				'no_dir', __('Dir not specified', 'fw')
			);
		}

		$dir = $args['dir'];

		if (file_exists($dir)) {
			if (!fw_ext_backups_rmdir_recursive($dir)) {
				return new WP_Error(
					'rmdir_fail', __('Cannot remove directory', 'fw') .': '. $dir
				);
			}
		}

		if (!mkdir($dir, 0777, true)) {
			return new WP_Error(
				'mkdir_fail', __('Cannot create directory', 'fw') .': '. $dir
			);
		}

		/**
		 * Additional backups dir security check (best-effort).
		 *
		 * These index.php / .htaccess files are only defense-in-depth (prevent
		 * directory listing and direct file access). On some managed hosts they
		 * cannot be created:
		 *  - WP Engine and similar platforms block writing *.php files into the
		 *    uploads directory as an anti-malware measure.
		 *  - .htaccess has no effect on nginx-based hosts.
		 * A failure here must NOT abort the backup, otherwise the whole process
		 * dies on the very first task. Try to create them, but continue on failure.
		 */
		{
			$backups_dir = fw_ext('backups')->get_backups_dir();

			if (!file_exists("$backups_dir/index.php")) {
				$contents = implode("\n", array(
					'<?php',
					'header(\'HTTP/1.0 403 Forbidden\');',
					'die(\'<h1>Forbidden</h1>\');'
				));
				@file_put_contents("$backups_dir/index.php", $contents);
			}

			if (!file_exists("$backups_dir/.htaccess")) {
				$contents = implode("\n", array(
					'Deny from all',
					'<IfModule mod_rewrite.c>',
					'    RewriteEngine On',
					'    RewriteRule . - [R=404,L]',
					'</IfModule>'
				));
				@file_put_contents("$backups_dir/.htaccess", $contents);
			}
		}

		return true;
	}
}
