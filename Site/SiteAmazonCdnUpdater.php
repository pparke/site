<?php

require_once 'SwatDB/SwatDB.php';
require_once 'SwatDB/SwatDBClassMap.php';
require_once 'Site/SiteConfigModule.php';
require_once 'Site/SiteDatabaseModule.php';
require_once 'Site/SiteAmazonCdnModule.php';
require_once 'Site/SiteCommandLineApplication.php';
require_once 'Site/dataobjects/SiteImageCdnTaskWrapper.php';

class SiteAmazonCdnUpdater extends SiteCommandLineApplication
{
	// {{{ public properties

	/**
	 * A convenience reference to the database object
	 *
	 * @var MDB2_Driver
	 */
	public $db;

	/**
	 * The base directory the files are saved to
	 *
	 * @var string
	 */
	public $source_dir;

	// }}}
	// {{{ public function run()

	/**
	 * Runs this application
	 */
	public function run()
	{
		$this->initInternal();

		$this->lock();
		$this->runInternal();
		$this->unlock();
	}

	// }}}
	// {{{ protected function initInternal()

	/**
	 * Initializes this application
	 */
	protected function initInternal()
	{
		$this->initModules();
		$this->parseCommandLineArguments();
	}

	// }}}
	// {{{ protected function runInternal()

	/**
	 * Runs this application
	 */
	protected function runInternal()
	{
		$tasks = $this->getTasks();
		$this->debug(sprintf("Found %s Tasks:\n", count($tasks)), true);

		foreach ($tasks as $task) {
			try {
				switch ($task->operation) {
				case 'copy':
					$this->copyImage($task);
					break;
				case 'delete':
					$this->deleteImage($task);
					break;
				}

				$task->delete();
			} catch (SwatFileNotFoundException $e) {
				$exception = $e;
				$exception->process(false);
			} catch (Services_Amazon_S3_Exception $e) {
				$exception = new SwatException($e);
				$exception->process(false);
			}
		}

		$this->debug("All Done.\n", true);
	}

	// }}}
	// {{{ protected function copyImage()

	/**
	 * Copies this taks's image to a CDN
	 *
	 * @param SiteImageCdnTask $task the copy task.
	 */
	protected function copyImage(SiteImageCdnTask $task)
	{
		if ($task->image instanceof SiteImage) {
			$this->debug(sprintf("Copying image %s, dimension %s ... ",
				$task->image->id,
				$task->dimension->shortname));

			$task->image->setFileBase($this->source_dir);
/*			$this->cdn->copyFile(
				$task->image->getFilePath($task->dimension->shortname),
				$task->image->getUriSuffix($task->dimension->shortname),
				$task->image->getMimeType($task->dimension->shortname));

			$task->image->setOnCdn(true, $task->dimension->shortname);
*/
			$this->debug("done.\n");
		}
	}

	// }}}
	// {{{ protected function deleteImage()

	/**
	 * Deletes this taks's image to a CDN
	 *
	 * @param SiteImageCdnTask $task the delete task.
	 */
	protected function deleteImage(SiteImageCdnTask $task)
	{
		if ($task->image instanceof SiteImage) {
			$task->image->setOnCdn(false, $task->dimension->shortname);
		}

		// prevent accidental attempts at deleting the entire bucket
		if (strlen($task->image_path)) {
			$this->debug(sprintf("Deleting CDN image %s ... ",
				$task->image_path));

			$this->cdn->deleteFile($task->image_path);

			$this->debug("done.\n");
		}
	}

	// }}}
	// {{{ protected function getTasks()

	/**
	 * Gets all outstanding CDN tasks
	 *
	 * @return SiteImageCdnTaskWrapper a recordset wrapper of all current tasks.
	 */
	protected function getTasks()
	{
		$wrapper = SwatDBClassMap::get('SiteImageCdnTaskWrapper');

		$sql = 'select * from ImageCdnQueue';

		$tasks = SwatDB::query($this->db, $sql, $wrapper);

		// efficiently load images
		$image_sql = 'select * from Image where id in (%s)';
		$images = $tasks->loadAllSubDataObjects(
			'image',
			$this->db,
			$image_sql,
			SwatDBClassMap::get('SiteImageWrapper'));

		// efficiently load dimensions
		$dimension_sql = 'select * from ImageDimension where id in (%s)';
		$dimensions = $tasks->loadAllSubDataObjects(
			'dimension',
			$this->db,
			$dimension_sql,
			SwatDBClassMap::get('SiteImageDimensionWrapper'));

		return $tasks;
	}

	// }}}

	// boilerplate code
	// {{{ protected function configure()

	protected function configure(SiteConfigModule $config)
	{
		parent::configure($config);

		$this->database->dsn = $config->database->dsn;

		$this->cdn->bucket_id         = $config->amazon->bucket;
		$this->cdn->access_key_id     = $config->amazon->access_key_id;
		$this->cdn->access_key_secret = $config->amazon->access_key_secret;
	}

	// }}}
	// {{{ protected function getDefaultModuleList()

	protected function getDefaultModuleList()
	{
		return array(
			'cdn'      => 'SiteAmazonCdnModule',
			'config'   => 'SiteConfigModule',
			'database' => 'SiteDatabaseModule',
		);
	}

	// }}}
}

?>
