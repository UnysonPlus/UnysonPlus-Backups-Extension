<?php if (!defined('FW')) die('Forbidden');
/**
 * @var null|FW_Ext_Backups_Task_Collection $active_task_collection
 * @var null|FW_Ext_Backups_Task $executing_task
 * @var null|FW_Ext_Backups_Task $pending_tasks
 */

/**
 * @var FW_Extension_Backups $backups
 */
$backups = fw_ext('backups');
?>
<?php if ($active_task_collection):
	/**
	 * Progress is derived from how many tasks in the collection have finished.
	 * The currently-executing task gets half credit so the bar keeps moving
	 * during long steps (files export, zip). Kept in 1..99 while running; the
	 * bar disappears once the collection is done (the "else" branch below).
	 */
	$all_tasks = $active_task_collection->get_tasks();
	$total_tasks = max(count($all_tasks), 1);
	$finished_tasks = 0;
	foreach ($all_tasks as $_task) {
		if ($_task->result_is_finished()) {
			$finished_tasks++;
		}
	}
	$progress_value = min($finished_tasks + 0.5, $total_tasks);
	$progress_percent = (int) max(1, min(99, round(($progress_value / $total_tasks) * 100)));
	?>
		<img src="<?php echo get_site_url() ?>/wp-admin/images/spinner.gif" alt="Loading">
		<em class="fw-text-muted"><?php
		if ($executing_task) {
			echo esc_html($backups->tasks()->get_task_type_title(
				$executing_task->get_type(),
				$executing_task->get_args(),
				$executing_task->get_result()
			));
		} elseif ($pending_tasks) {
			echo esc_html($backups->tasks()->get_task_type_title(
				$pending_tasks->get_type(),
				$pending_tasks->get_args(),
				$pending_tasks->get_result()
			));
		} else {
			esc_html_e('Unknown task');
		}
	?></em>
	<?php if ($active_task_collection->is_cancelable()): ?>
		<a href="#" onclick="fwEvents.trigger('fw:ext:backups:cancel'); return false;"><em><?php
			esc_html_e('Abort', 'fw');
		?></em></a>
	<?php endif; ?>
	<span class="fw-ext-backups-progress" role="progressbar"
	      aria-valuenow="<?php echo $progress_percent; ?>" aria-valuemin="0" aria-valuemax="100">
		<span class="fw-ext-backups-progress-bar" style="width: <?php echo $progress_percent; ?>%;"></span>
		<span class="fw-ext-backups-progress-text"><?php echo $progress_percent; ?>%</span>
	</span>
<?php else: ?>
	<em class="fw-text-muted" style="color: transparent;"><?php esc_html_e('Nothing running in background', 'fw'); ?></em>
<?php endif; ?>


