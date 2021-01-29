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
echo "<?php\n";
?>
class <?php echo $class; ?> extends CakeMigration {

/**
 * Migration description
 *
 * @var string
 */
	public $description = '<?php echo $name; ?>';

/**
* When clearning cache, default is to exclude the session key
* true = Don't exclude the session key, this will logout user sessions!
* false = default - exclude session key from being cleared
* @var bool
*/
	public $clearSessionCache = false;

/**
 * Actions to be performed
 *
 * @var array $migration
 */
	public $migration = array(
<?php echo $migration; ?>
	);

/**
 * Before migration callback
 *
 * @param string $direction Direction of migration process (up or down)
 * @return bool Should process continue
 */
	public function before($direction) {
		return true;
	}

/**
 * After migration callback
 *
 * @param string $direction Direction of migration process (up or down)
 * @return bool Should process continue
 */
	public function after($direction) {
		return true;
	}
}
