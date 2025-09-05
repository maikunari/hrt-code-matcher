<?php
namespace Aelia\WC\CurrencySwitcher\Install\Database;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\CurrencySwitcher\Traits\Logger_Trait;
use Aelia\WC\AFC\Traits\Database_Trait;
use Aelia\WC\AFC\Scheduler\Scheduled_Task_Settings;
use Aelia\WC\AFC\Scheduler\Task_Scheduler;
use Aelia\WC\CurrencySwitcher\Messages;
use Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher;

/**
 * Helper class to handle installation and update operations required by the
 * plugin.
 *
 * @since 4.16.0.230623
 */
class Database_Update_Manager {
	use Logger_Trait;
	use Database_Trait;

	/**
	 * The defulat number of seconds for which each scheduled task will be delayed. Each
	 * task will start at "now + number of seconds".
	 *
	 * @var int
	 */
	const DEFAULT_TASK_SCHEDULE_START_OFFSET = 120;

	/**
	 * Stores the database update manager settings.
	 *
	 * @var Database_Update_Manager_Settings
	 */
	protected $settings;

	/**
	 * Runs the database updates.
	 *
	 * @param Database_Update_Manager_Settings $settings
	 * @return void
	 */
	public static function run_updates(Database_Update_Manager_Settings $settings) {
		$database_update_manager = new static($settings);

		// The installation and upgrade process should run in the admin section,
		// and not during Ajax and Cron calls
		if(is_admin() && !(defined('DOING_AJAX') && DOING_AJAX) && !wp_doing_cron() && self::is_plugin_configured()) {
			$database_update_manager->update_database();
		}
	}

	/**
	 * Constructor.
	 *
	 * @param Database_Update_Manager_Settings $settings
	 */
	public function __construct(Database_Update_Manager_Settings $settings)	{
		$this->settings = $settings;

		// Initialise the logger, using the instance passed with the settings
		$this->set_logger($settings->logger_instance);
		self::$_debug_mode = $settings->debug_mode;

		// Register the task handlers that will process any task eventually scheduled. This
		// operation must be performed regardless of the page on which we are, because the
		// scheduled events could be triggered at any time
		$this->register_scheduled_tasks_handlers();

		// Allow 3rd parties, such as scheduled tasks, to re-schedule themselves by calling
		// an action, instead of calling the schedule_task() method directly
		// @since 4.16.0.230623
		add_action('aelia_cs_database_updater_schedule_task', [$this, 'schedule_task']);

		// Register an action that can be used to reset the database version via code.
		// Doing so will trigger the database update process from the beginning
		// @since 5.0.4.230626
		add_action('aelia_cs_database_updater_run_updates', [$this, 'aelia_cs_database_updater_run_updates']);
	}

	/**
	 * Registers the handlers that will process the scheduled tasks,.
	 *
	 * @return void
	 */
	protected function register_scheduled_tasks_handlers(): void {
		// Register the task handlers for the database update. The handlers will interface with the scheduler to
		// handle a scheduled task ID and find the existing schedules for the task
		$this->settings->task_scheduler->register_task_handlers(array_values($this->get_database_update_tasks_list()));
	}

	/**
	 * Updates the database to the next version, if needed.
	 *
	 * @return void
	 */
	public function update_database(): void {
		// Fetch the current version of the database
		$current_database_version = $this->get_database_version($this->settings->plugin_slug);

		// Fetch the next update that should be performed, if any.
		//
		// NOTE
		// The update to the next version might already be in progress. This method checks if
		// this is the case before scheduling the update
		$next_update_task_class = $this->get_next_update_task($current_database_version);

		if(!empty($next_update_task_class)) {
			// Fetch the task ID from the class
			$task_id = call_user_func([$next_update_task_class, 'get_id']);
			// Fetch the database version from the class
			$database_version = call_user_func([$next_update_task_class, 'get_task_database_version']);

			// Inform admins that database updated will be performed
			// @since 4.16.0.230623
			$pending_actions_url = admin_url('admin.php?page=wc-status&tab=action-scheduler&status=pending&s=' . $this->settings->plugin_slug);
			Messages::admin_message(
				wp_kses_post(implode(' ', [
					sprintf(__('The Currency Switcher is running some required updates to upgrade the database to version %1$s.', Definitions::TEXT_DOMAIN),
					'<span class="database_update_version">' . $database_version . '</span>'),
					__('This operation may take a while, it will run automatically in the background.', Definitions::TEXT_DOMAIN),
					__('Thank you for your patience.', Definitions::TEXT_DOMAIN),
					'<br /><br />',
					sprintf('<a class="button" href="%2$s">%1$s</a>', __('View progress', Definitions::TEXT_DOMAIN), $pending_actions_url),
					sprintf('<a class="button" target="_blank" href="%2$s">%1$s</a>', __('Learn more about these updates', Definitions::TEXT_DOMAIN), Definitions::URL_DATABASE_UPDATES_INFORMATION),
				])),
				[
					'sender_id' => $this->settings->plugin_slug,
					'level' => E_USER_NOTICE,
					'code' => Definitions::NOTICE_DATABASE_UPDATES_RUNNING,
					'dismissable' => false,
					'permissions' => 'manage_woocommerce',
					'message_header' => __('Database updates in progress', Definitions::TEXT_DOMAIN),
				]
			);

			// Build the settings for the scheduled task
			$scheduled_task_settings = $this->get_scheduled_task_settings($task_id, []);

			// If the task hasn't yet been schedule, schedule it now
			if(!$this->settings->task_scheduler->is_scheduled($scheduled_task_settings)) {
				$this->schedule_task($scheduled_task_settings);
			}
			else {
				$this->get_logger()->debug(esc_html(implode(' ', [
					__('Database update task already scheduled.', Definitions::TEXT_DOMAIN),
					__('Waiting for the task to complete before proceeding with the next task.', Definitions::TEXT_DOMAIN),
				])), [
					'Task ID' => $task_id,
				]);
			}

			add_filter('wc_aelia_cs_order_data_import_running', '__return_true');
		}
		else {
			// If there are no updates to run, and the database version is lower than the plugin version,
			// bump the database version to the current one. The check is needed to ensure that the bump
			// is performed only once for each new plugin version
			// @since 5.0.5.230703
			if(version_compare($current_database_version, $this->settings->plugin_version, '<')) {
				$this->set_database_version($this->settings->plugin_slug, $this->settings->plugin_version);
			}
		}
	}

	/**
	 * Indicates if the plugin for which the database update manager is running has been configured.
	 * This will allow to skip the update process if the plugins hasn't yet been set up.
	 *
	 * @return boolean
	 * @since 4.16.0.230623
	 */
	protected static function is_plugin_configured(): bool {
		static $is_plugin_configured = null;

		// Cache the plugin configuration status
		if($is_plugin_configured === null) {
			$is_plugin_configured = !empty(WC_Aelia_CurrencySwitcher::settings()->current_settings());
		}
		return $is_plugin_configured;
	}

	/**
	 * Returns a list of version -> task entries. Each task will be used to
	 * update the database to the corresponding version.
	 *
	 * @return array
	 */
	protected function get_database_update_tasks_list(): array {
		// Common updates, which can run with both the legacy database and the HPOS tables
		$updates_list = [
			// Replaced the IDs of exchange rates providers with the latest ones, if needed
			'4.1.6.210825' => '\Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Database_Updater_Version_4_12_6_210825',
		];

		if(aelia_is_hpos_feature_enabled()) {
			// If the HPOS feature is enabled, load the classes to update the HPOS tables
			$namespace = '\Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\HPOS';
			$updates_list = array_merge($updates_list, [
				// Calculate amounts in shop base currency for orders and their items
				'5.0.3.230626' => $namespace . '\Database_Updater_Version_5_0_3_230626',
				// Calculate amounts in shop base currency for "orphaned" refunds and their items
				'5.0.4.230626' => $namespace . '\Database_Updater_Version_5_0_4_230626',
			]);
		}
		else {
			// If the HPOS feature is disabled, load the classes to update the legacy tables
			$namespace = '\Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Legacy';
			$updates_list = array_merge($updates_list, [
				// Calculate amounts in shop base currency for orders and their items
				'5.0.3.230626' => $namespace . '\Database_Updater_Version_5_0_3_230626',
				// Calculate amounts in shop base currency for "orphaned" refunds and their items
				'5.0.4.230626' => $namespace . '\Database_Updater_Version_5_0_4_230626',
			]);
		}

		// Sort the updates by version in ascending order
		uksort($updates_list, function($a, $b) {
			return version_compare($a, $b);
		});

		return $updates_list;
	}

	/**
	 * Returns the next update task to be performed.
	 *
	 * @params string $current_database_version The current version of the
	 * database, used to determine which updates should be applied.
	 * @return string|null
	 */
	protected function get_next_update_task(string $current_database_version): ?string {
		foreach($this->get_database_update_tasks_list() as $version => $task_class) {
			if(version_compare($version, $current_database_version, '>')) {
				return $task_class;
			}
		}
		return null;
	}

	/**
	 * Returns an object with the settings to schedule a task.
	 *
	 * @param string $task_id The ID of the task to be scheduled. The appropriate task handler
	 * will take care of performing it.
	 * @param array $scheduled_event_args Additional arguments to passs to the scheduled task.
	 * @return Scheduled_Task_Settings
	 */
	protected function get_scheduled_task_settings(string $task_id, array $scheduled_event_args = []): Scheduled_Task_Settings {
		// Calculate the offset to be added to the starting time for the scheduled task
		$start_time_offset = apply_filters('wc_aelia_cs_scheduled_task_start_time_offset', self::DEFAULT_TASK_SCHEDULE_START_OFFSET, $task_id, $scheduled_event_args);
		// Ensure that the start time offset is a valid integer
		$start_time_offset = is_int($start_time_offset) ? intval($start_time_offset) : self::DEFAULT_TASK_SCHEDULE_START_OFFSET;

		return new Scheduled_Task_Settings([
			'group' => $this->settings->plugin_slug,
			'task_id' => $task_id,
			// Run the task every 5 minutes
			'interval' => 0,
			'start_timestamp' => time() + $start_time_offset,
			'debug_mode' => $this->settings->debug_mode,
			'reset_existing_schedule' => true,
			'scheduled_event_args' => array_merge($scheduled_event_args, [
				'plugin_slug' => $this->settings->plugin_slug,
				// @since 5.0.5.230703
				'plugin_version' => $this->settings->plugin_version,
				// @since 5.0.8.230720
				'batch_size' => apply_filters('wc_aelia_cs_database_updater_batch_size', Definitions::DATABASE_UPDATER_DEFAULT_BATCH_SIZE, $task_id),
			]),
		]);
	}

	/**
	 * Schedules a task and logs the result of the operation.
	 *
	 * @param Scheduled_Task_Settings $task_settings
	 * @return void
	 */
	public function schedule_task(Scheduled_Task_Settings $task_settings): void {
		$this->get_logger()->notice(__('Scheduling database update task.', Definitions::TEXT_DOMAIN), [
			'Task ID' => $task_settings->task_id,
		]);

		// Tries to schedule the task, fetching the result of the operation
		$scheduled_action_id = $this->settings->task_scheduler->schedule_task($task_settings);

		if(is_numeric($scheduled_action_id)) {
			$this->get_logger()->notice(__('Database update task scheduled successfully.', Definitions::TEXT_DOMAIN), [
				'Scheduled Action ID' => $scheduled_action_id,
				'Task ID' => $task_settings->task_id,
				'Task Settings' => $task_settings,
			]);
		}
		else {
			$this->get_logger()->notice(__('Could not schedule the database update task.', Definitions::TEXT_DOMAIN), [
				'Task ID' => $task_settings->task_id,
				'Task Settings' => $task_settings,
			]);
		}
	}

	/**
	 * Clears all the database update scheduled tasks.
	 *
	 * @return void
	 */
	protected function clear_scheduled_tasks(array $tasks_list): void {
		foreach($tasks_list as $task_id) {
			$this->get_logger()->info(__('Cancelling scheduled tasks for database updates.', Definitions::TEXT_DOMAIN), [
				'Task ID' => $task_id,
			]);

			Task_Scheduler::instance()->cancel_all_task_events(new Scheduled_Task_Settings([
				'task_id' => $task_id,
			]));
		}
	}

	/**
	 * Queues and runs the database updates.
	 *
	 * @param bool $run_all_updates If set to true, the database version is going to be reset, forcing the
	 * updates to run from the beginning. This can be done to repeat the import of sales data from the orders.
	 * @return void
	 * @since 5.0.4.230626
	 */
	public function aelia_cs_database_updater_run_updates(bool $run_all_updates = false): void {
		// If action "aelia_cs_database_updater_run_updates" was already triggered, stop here.
		// There's no need to trigger it twice within a single page load
		if(did_action('aelia_cs_database_updater_run_updates') && !doing_action('aelia_cs_database_updater_run_updates')) {
			$this->get_logger()->warning(implode(' ', [
				__('Action "aelia_cs_database_updater_run_updates" has already been triggered.', Definitions::TEXT_DOMAIN),
				__('Skipping the operation.', Definitions::TEXT_DOMAIN),
			]), [
				'Current User' => wp_get_current_user(),
			]);

			return;
		}

		if($run_all_updates) {
			$this->get_logger()->notice(implode(' ', [
				__('Event "aelia_cs_database_updater_run_updates" was triggered to force all updates.', Definitions::TEXT_DOMAIN),
				__('Resetting the database version, to repeat the database upgrade process.', Definitions::TEXT_DOMAIN),
			]), [
				'Current User' => wp_get_current_user(),
				'Current Database Version' => $this->get_database_version($this->settings->plugin_slug),
			]);

			// Reset the database version to perform all the updates from the beginning
			$this->set_database_version($this->settings->plugin_slug, '0.0');
		}

		// Run the updates
		$this->update_database();
	}
}
