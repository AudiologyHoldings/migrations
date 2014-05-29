<?php
/**
 * Copyright 2009 - 2014, Cake Development Corporation (http://cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2009 - 2014, Cake Development Corporation (http://cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

App::uses('CakeSchema', 'Model');

/**
 * Base Class for Migration management
 *
 */
class CakeMigration extends Object {

/**
 * Migration description
 *
 * @var string
 */
	public $description = '';

/**
 * Migration dependencies
 *
 * @var array
 */
	public $dependencies = array();

/**
 * Migration information
 *
 * This variable will be set while the migration is running and contains:
 * - `name` - File name without extension
 * - `class` - Class name
 * - `version` - What version represent on mapping
 * - `type` - Can be 'app' or a plugin name
 * - `migrated` - Datetime of when it was applied, or null
 *
 * @var array
 */
	public $info = null;

/**
 * Actions to be performed
 *
 * @var array $migration
 */
	public $migration = array(
		'up' => array(),
		'down' => array()
	);

/**
 * Running direction
 *
 * @var string $direction
 */
	public $direction = null;

/**
 * Connection used
 *
 * @var string
 */
	public $connection = 'default';

/**
 * DataSource used
 *
 * @var DboSource
 */
	public $db = null;

/**
 * MigrationVersion instance
 *
 * @var MigrationVersion
 */
	public $Version = null;

/**
 * CakeSchema instance
 *
 * @var CakeSchema
 */
	public $Schema = null;

/**
 * Callback class that will be called before/after every action
 *
 * @var object
 */
	public $callback = null;

/**
 * Precheck object executed before db updated
 *
 * @var PrecheckBase
 */
	public $Precheck = null;

/**
 * Should the run be dry or not.
 *
 * If try, the SQL will be outputted to screen rather than
 * applied to the database
 *
 * @var boolean
 */
	public $dry = false;

/**
 * Log of SQL queries generated
 *
 * This is used for dry run
 *
 * @var array
 */
	public $log = array();

/**
 *
 * Alters are placed in this array one at a time.  Later,
 * they are combined into one alter statement per table.
 *
 * @var array
 */
	public $storedAlters = array();

/**
 * Before migration callback
 *
 * @param string $direction, up or down direction of migration process
 * @return boolean Should process continue
 */
	public function before($direction) {
		return true;
	}

/**
 * After migration callback
 *
 * @param string $direction, up or down direction of migration process
 * @return boolean Should process continue
 */
	public function after($direction) {
		return true;
	}

/**
 * Log a dry run SQL query
 *
 * @param str $sql
 * @return void
 */
	public function logQuery($sql) {
		$this->log[] = $sql;
	}

/**
 * Get the SQL query log
 *
 * @return array
 */
	public function getQueryLog() {
		return $this->log;
	}

/**
 * Constructor
 *
 * @param array $options optional load object properties
 */
	public function __construct($options = array()) {
		parent::__construct();

		if (!isset($options['dry'])) {
			$options['dry'] = false;
		}

		$this->dry = $options['dry'];

		if (!empty($options['up'])) {
			$this->migration['up'] = $options['up'];
		}
		if (!empty($options['down'])) {
			$this->migration['down'] = $options['down'];
		}

		$allowed = array('connection', 'callback');

		foreach ($allowed as $variable) {
			if (!empty($options[$variable])) {
				$this->{$variable} = $options[$variable];
			}
		}

		if (empty($options['precheck'])) {
			App::uses('PrecheckException', 'Migrations.Lib/Migration');
			$this->Precheck = new PrecheckException();
		} else {
			$class = Inflector::camelize($options['precheck']);
			list($plugin, $class) = pluginSplit($class, true);
			App::uses($class, $plugin . 'Lib/Migration');

			if (!class_exists($class)) {
				throw new MigrationException($this, sprintf(
					__d('migrations', 'Migration precheck class (%s) could not be loaded.'), $options['precheck']
				), E_USER_NOTICE);
			}

			$this->Precheck = new $class();

			if (!is_a($this->Precheck, 'PrecheckBase')) {
				throw new MigrationException($this, sprintf(
					__d('migrations', 'Migration precheck class (%s) is not a valid precheck class.'), $class
				), E_USER_NOTICE);
			}
		}
	}

/**
 * Run migration
 *
 * @param string $direction, up or down direction of migration process
 * @return boolean Status of the process
 * @throws MigrationException
 */
	public function run($direction) {
		if (!in_array($direction, array('up', 'down'))) {
			throw new MigrationException($this, sprintf(
				__d('migrations', 'Migration direction (%s) is not one of valid directions.'), $direction
			), E_USER_NOTICE);
		}
		$this->direction = $direction;

		$null = null;
		$this->db = ConnectionManager::getDataSource($this->connection);
		$this->db->cacheSources = false;
		$this->db->begin($null);
		$this->Schema = new CakeSchema(array('connection' => $this->connection));

		try {
			$this->_invokeCallbacks('beforeMigration', $direction);
			$result = $this->_run();
			$this->_clearCache();
			$this->_invokeCallbacks('afterMigration', $direction);

			if (!$result) {
				return false;
			}
		} catch (Exception $e) {
			$this->db->rollback($null);
			throw $e;
		}

		return $this->db->commit($null);
	}

/**
 * Run migration commands
 *
 * @return void
 * @throws MigrationException
 */
	protected function _run() {
		$result = true;
		$this->storedAlters = array();
		//force the order of migration types
		uksort($this->migration[$this->direction], array($this, 'migration_order'));
		foreach ($this->migration[$this->direction] as $type => $info) {
			switch ($type) {
				case 'create_table':
					$methodName = '_createTable';
					break;
				case 'drop_table':
					$methodName = '_dropTable';
					break;
				case 'rename_table':
					$methodName = '_renameTable';
					break;
				case 'create_field':
					$type = 'add';
					$methodName = '_alterTable';
					break;
				case 'drop_field':
					$type = 'drop';
					$methodName = '_alterTable';
					break;
				case 'alter_field':
					$type = 'change';
					$methodName = '_alterTable';
					break;
				case 'rename_field':
					$type = 'rename';
					$methodName = '_alterTable';
					break;
				default:
					$message = __d('migrations', 'Migration action type (%s) is not one of valid actions type.', $type);
					throw new MigrationException($this, $message, E_USER_NOTICE);
			}

			try {
				$result = $this->{$methodName}($type, $info);
			} catch (Exception $e) {
				throw new MigrationException($this, sprintf(__d('migrations', '%s'), $e->getMessage()));
			}
		}

		// Run all alters that were stored.
		try {
			$result = $this->_runStoredAlters();
		} catch (Exception $e) {
			throw new MigrationException($this, sprintf(__d('migrations', '%s'), $e->getMessage()));
		}

		return $result;
	}

/**
 * Comparison method for sorting migration types
 *
 * @param string $a Type
 * @param string $b Type
 * @return integer Comparison value
 */
	protected function migration_order($a, $b) {
		$order = array('drop_table', 'rename_table', 'create_table', 'drop_field', 'rename_field', 'alter_field', 'create_field');

		return array_search($a, $order) - array_search($b, $order);
	}

/**
 * Create Table method
 *
 * @param string $type Type of operation to be done, in this case 'create_table'
 * @param array $tables List of tables to be created
 * @return boolean Return true in case of success, otherwise false
 * @throws MigrationException
 */
	protected function _createTable($type, $tables) {
		foreach ($tables as $table => $fields) {
			if ($this->_invokePrecheck('beforeAction', 'create_table', array('table' => $table))) {
				$this->Schema->tables = array($table => $fields);
				$this->_invokeCallbacks('beforeAction', 'create_table', array('table' => $table));
				try {
					$sql = $this->db->createSchema($this->Schema);

					if ($this->dry) {
						$this->logQuery($sql);
						continue;
					}

					$this->db->execute($sql);
				} catch (Exception $exception) {
					throw new MigrationException($this, __d('migrations', 'SQL Error: %s', $exception->getMessage()));
				}
			}

			$this->_invokeCallbacks('afterAction', 'create_table', array('table' => $table));
		}
		return true;
	}

/**
 * Drop Table method
 *
 * @param string $type Type of operation to be done, in this case 'drop_table'
 * @param array $tables List of tables to be dropped
 * @return boolean Return true in case of success, otherwise false
 * @throws MigrationException
 */
	protected function _dropTable($type, $tables) {
		foreach ($tables as $table) {
			$this->Schema->tables = array($table => array());
			if ($this->_invokePrecheck('beforeAction', 'drop_table', array('table' => $table))) {
				$this->_invokeCallbacks('beforeAction', 'drop_table', array('table' => $table));

				$sql = $this->db->dropSchema($this->Schema);
				if ($this->dry) {
					$this->logQuery($sql);
					continue;
				}

				if (@$this->db->execute($sql) === false) {
					throw new MigrationException($this, sprintf(__d('migrations', 'SQL Error: %s'), $this->db->error));
				}
				$this->_invokeCallbacks('afterAction', 'drop_table', array('table' => $table));
			}
		}
		return true;
	}

/**
 * Rename Table method
 *
 * @param string $type Type of operation to be done, this case 'rename_table'
 * @param array $tables List of tables to be renamed
 * @return boolean Return true in case of success, otherwise false
 * @throws MigrationException
 */
	protected function _renameTable($type, $tables) {
		foreach ($tables as $oldName => $newName) {
			if ($this->_invokePrecheck('beforeAction', 'rename_table', array('old_name' => $oldName, 'new_name' => $newName))) {
				$sql = 'ALTER TABLE ' . $this->db->fullTableName($oldName) . ' RENAME TO ' . $this->db->fullTableName($newName, true, false) . ';';

				if ($this->dry) {
					$this->logQuery($sql);
					return true;
				}

				$this->_invokeCallbacks('beforeAction', 'rename_table', array('old_name' => $oldName, 'new_name' => $newName));
				if (@$this->db->execute($sql) === false) {
					throw new MigrationException($this, __d('migrations', 'SQL Error: %s', $this->db->error));
				}
				$this->_invokeCallbacks('afterAction', 'rename_table', array('old_name' => $oldName, 'new_name' => $newName));
			}
		}
		return true;
	}

/**
 * Run all alters that were stored by _alterTable().  They will be combined into one statement.
 *
 * @return boolean Return true in case of success, otherwise false
 */
	protected function _runStoredAlters() {
		foreach ($this->storedAlters as $table => $alters) {
			// Generate SQL for this table
			$sql = $this->db->alterSchema(array($table => $alters));

			// Skip if dry
			if ($this->dry) {
				$this->logQuery($sql);
				continue;
			}

			// Run ALL beforeAction callbacks.
			foreach ($alters as $type => $definition) {
				foreach ($definition as $field => $details) {
					if ($field == 'indexes') {
						foreach ($details as $key => $key_details) {
							$this->_invokeCallbacks('beforeAction', $type . '_index', array('table' => $table, 'index' => $key));
						}
						continue;
					}

					if ($type == 'rename') {
						$callbackData = array('table' => $table, 'old_name' => $field, 'new_name' => $details);
					} else {
						$callbackData = array('table' => $table, 'field' => $field);
					}
					$this->_invokeCallbacks('beforeAction', $type . '_field', $callbackData);
				}
			}

			// Run combined alters.
			if (@$this->db->execute($sql) === false) {
				throw new MigrationException($this, sprintf(__d('migrations', 'SQL Error: %s'), $this->db->error));
			}

			// Run ALL afterAction callbacks.
			foreach ($alters as $type => $definition) {
				foreach ($definition as $field => $details) {
					if ($field == 'indexes') {
						foreach ($details as $key => $key_details) {
							$this->_invokeCallbacks('afterAction', $type . '_index', array('table' => $table, 'index' => $key));
						}
						continue;
					}

					if ($type == 'rename') {
						$callbackData = array('table' => $table, 'old_name' => $field, 'new_name' => $details);
					} else {
						$callbackData = array('table' => $table, 'field' => $field);
					}
					$this->_invokeCallbacks('afterAction', $type . '_field', $callbackData);
				}
			}
		}
		return true;
	}

/**
 * Alter Table method.
 * Only puts alters to be done inside $this->storedAlters.  You must run _runStoredAlters() after
 * you are done with all calls to _alterTable().
 *
 * @param string $type Type of operation to be done
 * @param array $tables List of tables and fields
 * @return boolean Return true in case of success, otherwise false
 * @throws MigrationException
 */
	protected function _alterTable($type, $tables) {
		foreach ($tables as $table => $fields) {

			// Initalize storedAlters for this table
			if (empty($this->storedAlters[$table])) {
				$this->storedAlters[$table] = array(
					'add'    => array(),
					'drop'   => array(),
					'change' => array(),
				);
			}

			$indexes = array();
			if (isset($fields['indexes'])) {
				$indexes = $fields['indexes'];
				unset($fields['indexes']);

				if ($type == 'drop') {
					if ($indexes == array_filter($indexes, 'is_string')) {
						$indexes = array_flip($indexes);
					}
				}

				$prechecksPassed = true;
				foreach ($indexes as $key => $key_details) {
					if (!$this->_invokePrecheck('beforeAction', $type . '_index', array('table' => $table, 'index' => $key))) {
						$prechecksPassed = false;
						break;
					}
				}
				if ($prechecksPassed) {
					$this->storedAlters[$table][$type]['indexes'] = $indexes;
				}
			}

			foreach ($fields as $field => $col) {
				$model = new Model(array('table' => $table, 'ds' => $this->connection));
				$tableFields = $this->db->describe($model);
				$tableFields['indexes'] = $this->db->index($model);
				$tableFields['tableParameters'] = $this->db->readTableParameters($this->db->fullTableName($model, false, false));
				
				if ($type === 'drop') {
					$field = $col;
				}

				if ($type === 'rename') {
					$data = array('table' => $table, 'old_name' => $field, 'new_name' => $col);
				} else {
					$data = array('table' => $table, 'field' => $field);
				}

				if ($this->_invokePrecheck('beforeAction', $type . '_field', $data)) {
					switch ($type) {
						case 'add':
							$this->storedAlters[$table]['add'][$field] = $col;
							break;
						case 'drop':
							$this->storedAlters[$table]['drop'][$field] = array();
							break;
						case 'change':
							if (!isset($col['type']) || $col['type'] == $tableFields[$field]['type']) {
								$def = array_merge($tableFields[$field], $col);
							} else {
								$def = $col;
							}
							if (!empty($def['length']) && !empty($col['type']) && (substr($col['type'], 0, 4) === 'date' || substr($col['type'], 0, 4) === 'time')) {
								$def['length'] = null;
							}
							$this->storedAlters[$table]['change'][$field] = $def;
							break;
						case 'rename':
							$data = array();
							if (array_key_exists($field, $tableFields)) {
								$data = $tableFields[$field];
							}
							$this->storedAlters[$table]['change'][$field] = array_merge($data, array('name' => $col));
							break;
					}
				}
			}
		}
		return true;
	}

/**
 * This method will invoke the before/afterAction callbacks, it is good when
 * you need track every action.
 *
 * @param string $callback Callback name, beforeMigration, beforeAction, afterAction
 * 		or afterMigration.
 * @param string $type Type of action. i.e: create_table, drop_table, etc.
 * 		Or also can be the direction, for before and after Migration callbacks
 * @param array $data Data to send to the callback
 * @return void
 * @throws MigrationException
 */
	protected function _invokeCallbacks($callback, $type, $data = array()) {
		if ($this->dry) {
			return true;
		}

		if ($this->callback !== null && method_exists($this->callback, $callback)) {
			if ($callback === 'beforeMigration' || $callback === 'afterMigration') {
				$this->callback->{$callback}($this, $type);
			} else {
				$this->callback->{$callback}($this, $type, $data);
			}
		}
		if ($callback === 'beforeMigration' || $callback === 'afterMigration') {
			$callback = str_replace('Migration', '', $callback);
			if ($this->{$callback}($type)) {
				return;
			}

			throw new MigrationException($this, sprintf(
				__d('migrations', 'Interrupted when running "%s" callback.'), $callback
			), E_USER_NOTICE);
		}
	}

/**
 * This method will invoke the before/afterAction callbacks, it is good when
 * you need track every action.
 *
 * @param string $callback Callback name, beforeMigration, beforeAction
  *         or afterMigration.
 * @param string $type Type of action. i.e: create_table, drop_table, etc.
 *         Or also can be the direction, for before and after Migration callbacks
 * @param array $data Data to send to the callback
 * @return boolean
 */
	protected function _invokePrecheck($callback, $type, $data = array()) {
		if ($this->dry) {
			return true;
		}

		if ($callback === 'beforeAction') {
			return $this->Precheck->{$callback}($this, $type, $data);
		}
		return false;
	}

/**
 * Clear all caches present related to models
 *
 * Before the 'after' callback method be called is needed to clear all caches.
 * Without it any model operations will use cached data instead of real/modified
 * data.
 *
 * @return void
 */
	protected function _clearCache() {
		// Clear the cache
		DboSource::$methodCache = array();
		$keys = Cache::configured();
		foreach ($keys as $key) {
			Cache::clear(false, $key);
		}
		ClassRegistry::flush();

		// Refresh the model, in case something changed
		if ($this->Version instanceof MigrationVersion) {
			$this->Version->initVersion();
		}
	}

/**
 * Generate a instance of model for given options
 *
 * @param string $name Model name to be initialized
 * @param string $table Table name to be initialized
 * @param array $options
 * @return Model
 */
	public function generateModel($name, $table = null, $options = array()) {
		if (empty($table)) {
			$table = Inflector::tableize($name);
		}
		$defaults = array(
			'name' => $name, 'table' => $table, 'ds' => $this->connection
		);
		$options = array_merge($defaults, $options);

		return new AppModel($options);
	}

}

/**
 * Exception used when something goes wrong on migrations
 *
 */
class MigrationException extends Exception {

/**
 * Reference to the Migration being processed on time the error ocurred
 * @var CakeMigration
 */
	public $Migration;

/**
 * Constructor
 *
 * @param CakeMigration $Migration Reference to the Migration
 * @param string $message Message explaining the error
 * @param integer $code Error code
 * @return \MigrationException
 */
	public function __construct($Migration, $message = '', $code = 0) {
		parent::__construct($message, $code);
		$this->Migration = $Migration;
	}
}
