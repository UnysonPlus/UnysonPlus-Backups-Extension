<?php if (!defined('FW')) die('Forbidden');
/**
 * @var string $archives_html
 */
?>

<?php
$backups = fw_ext( 'backups' ); /** @var FW_Extension_Backups $backups */
$page_url = $backups->get_page_url();
?>
<h2><?php esc_html_e('Backup', 'fw') ?> <span id="fw-ext-backups-status"></span></h2>

<div>
	<?php if ( !class_exists('ZipArchive') ): ?>
		<div class="error below-h2">
			<p>
				<strong><?php _e( 'Important', 'fw' ); ?></strong>:
				<?php printf(
					__( 'You need to activate %s.', 'fw' ),
					'<a href="http://php.net/manual/en/book.zip.php" target="_blank">'. __('zip extension', 'fw') .'</a>'
				); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ($http_loopback_warning = fw_ext_backups_loopback_test()) : ?>
		<div class="error">
			<p><strong><?php _e( 'Important', 'fw' ); ?>:</strong> <?php echo $http_loopback_warning; ?></p>
		</div>
		<script type="text/javascript">var fw_ext_backups_loopback_failed = true;</script>
	<?php endif; ?>

	<div class="fw-ext-backups-description">
		<p class="description"><?php esc_html_e( 'Here you can create a backup schedule for your website.', 'fw' ); ?></p>
		<ul>
			<?php if (fw_ext_backups_current_user_can_full()): ?>
			<li>
				<span class="description">
				<strong><?php esc_html_e('Full Backup', 'fw'); ?></strong>
				- <?php esc_html_e('will save your themes, plugins, uploads and full database.'); ?>
				</span>
			</li>
			<?php endif; ?>
			<li>
				<span class="description">
				<strong><?php esc_html_e('Content Backup', 'fw'); ?></strong>
				- <?php esc_html_e('will save your uploads and database without private data like users, admin email, etc.'); ?>
				</span>
			</li>
		</ul>
	</div>

	<div id="fw-ext-backups-schedule-status"></div>

	<div>
		<a href="#" onclick="return false;" id="fw-ext-backups-edit-schedule"
		   class="button button-primary"><?php esc_html_e( 'Edit Backup Schedule', 'fw' ) ?></a>
		&nbsp;
		<?php if (fw_ext_backups_current_user_can_full()): ?>
		<a href="#" onclick="return false;" id="fw-ext-backups-full-backup-now"
		   class="button fw-ext-backups-backup-now" data-full="1"><?php esc_html_e('Create Full Backup Now', 'fw') ?></a>
		&nbsp;
		<?php endif; ?>
		<a href="#" onclick="return false;" id="fw-ext-backups-content-backup-now"
		   class="button fw-ext-backups-backup-now" data-full=""><?php esc_html_e('Create Content Backup Now', 'fw'); ?></a>
	</div>
</div>

<?php
/**
 * Selective backup + auto-cleanup panel (@since 2.0.41)
 */
$selectable = $backups->get_selectable_dirs();
$excluded   = $backups->get_excluded_dirs();
$keep_last  = $backups->get_keep_last();
$col_labels = array(
	'plugins' => __('Plugins', 'fw'),
	'themes'  => __('Themes', 'fw'),
	'uploads' => __('Uploads', 'fw'),
);
?>
<div id="fw-ext-backups-options" class="fw-ext-backups-options">
	<h3>
		<a href="#" onclick="return false;" class="fw-ext-backups-options-toggle">
			<span class="dashicons dashicons-arrow-right-alt2"></span>
			<?php esc_html_e('Selective Backup &amp; Cleanup', 'fw'); ?>
		</a>
	</h3>

	<div class="fw-ext-backups-options-body" style="display:none;">
		<p class="description">
			<?php esc_html_e('Uncheck a folder to exclude it from backups. Plugins and Themes apply to Full backups; Uploads applies to both Full and Content backups.', 'fw'); ?>
		</p>

		<div class="fw-ext-backups-columns">
			<?php foreach ($col_labels as $cat => $label): ?>
				<div class="fw-ext-backups-column" data-cat="<?php echo esc_attr($cat); ?>">
					<div class="fw-ext-backups-column-head">
						<label>
							<input type="checkbox" class="fw-ext-backups-checkall" checked>
							<strong><?php echo esc_html($label); ?></strong>
						</label>
					</div>
					<ul>
						<?php if (empty($selectable[$cat])): ?>
							<li><em class="fw-text-muted"><?php esc_html_e('No folders', 'fw'); ?></em></li>
						<?php else: foreach ($selectable[$cat] as $name): ?>
							<li>
								<label>
									<input type="checkbox" class="fw-ext-backups-dir"
									       value="<?php echo esc_attr($name); ?>"
									       <?php checked(!isset($excluded[$cat][$name])); ?>>
									<?php echo esc_html($name); ?>
								</label>
							</li>
						<?php endforeach; endif; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>

		<p class="fw-ext-backups-keep-last">
			<label>
				<?php esc_html_e('Keep only the last', 'fw'); ?>
				<input type="number" min="0" step="1" id="fw-ext-backups-keep-last"
				       value="<?php echo esc_attr($keep_last); ?>" style="width:70px;">
				<?php esc_html_e('backups (0 = keep all). Older archives are deleted automatically after each new backup.', 'fw'); ?>
			</label>
		</p>

		<p>
			<a href="#" onclick="return false;" id="fw-ext-backups-save-options"
			   class="button button-primary"><?php esc_html_e('Save selection', 'fw'); ?></a>
			<span id="fw-ext-backups-options-msg" class="fw-text-muted" style="margin-left:8px;"></span>
		</p>
	</div>
</div>

<br>
<h3><?php _e( 'Archives', 'fw' ) ?></h3>

<div id="fw-ext-backups-archives"><?php echo $archives_html; ?></div>

<div id="fw-ext-backups-upload" class="fw-ext-backups-upload">
	<h4><?php esc_html_e('Upload a Backup', 'fw'); ?></h4>
	<p class="description">
		<?php esc_html_e('Upload a .zip backup created by this extension to add it to the archives above, then you can Restore it.', 'fw'); ?>
	</p>
	<p>
		<input type="file" id="fw-ext-backups-upload-file" accept=".zip,application/zip">
		<a href="#" onclick="return false;" id="fw-ext-backups-upload-button"
		   class="button"><?php esc_html_e('Upload Backup', 'fw'); ?></a>
		<span id="fw-ext-backups-upload-msg" class="fw-text-muted" style="margin-left:8px;"></span>
	</p>
</div>

<br>
<?php do_action('fw_ext_backups_page_footer'); ?>