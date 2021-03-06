<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2014 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') or die();

JLoader::import('joomla.application.component.model');

class ArsModelBleedingedge extends F0FModel
{

	private $category_id;

	private $category;

	private $folder = null;

	/**
	 * Public constructor. Preloads the Amazon S3 handling class.
	 *
	 * @param array $config
	 */
	public function __construct($config = array())
	{
		parent::__construct($config);

		require_once JPATH_ADMINISTRATOR . '/components/com_ars/helpers/amazons3.php';
	}

	/**
	 * Sets the category we are operating on
	 *
	 * @param ArsTableCategory|integer $cat A category table or a numeric category ID
	 *
	 * @return void
	 */
	protected function setCategory($cat)
	{
		// Initialise
		$this->category = null;
		$this->category_id = null;
		$this->folder = null;

		if ($cat instanceof ArsTableCategory)
		{
			$this->category = $cat;
			$this->category_id = $cat->id;
		}
		elseif (is_numeric($cat))
		{
			$this->category_id = (int)$cat;
			$this->category = F0FModel::getTmpInstance('Categories', 'ArsModel')
									  ->getItem($this->category_id);
		}

		// Store folder
		$folder = $this->category->directory;

		// Check for categories stored in Amazon S3
		$potentialPrefix = substr($folder, 0, 5);
		$potentialPrefix = strtolower($potentialPrefix);

		if ($potentialPrefix == 's3://')
		{
			// If it is stored on S3 make sure there are files stored with the given directory prefix
			$check = substr($folder, 5);
			$s3 = ArsHelperAmazons3::getInstance();
			$items = $s3->getBucket('', $check . '/');

			if (empty($items))
			{
				return;
			}
		}
		else
		{
			// If it is stored locally, make sure the folder exists
			JLoader::import('joomla.filesystem.folder');

			if (!JFolder::exists($folder))
			{
				$folder = JPATH_ROOT . '/' . $folder;

				if (!JFolder::exists($folder))
				{
					return;
				}
			}
		}

		$this->folder = $folder;
	}

	/**
	 * Scan a bleeding edge category
	 *
	 * @param ArsTableCategory $a_category The category to scan (category table or numeric category ID)
	 *
	 * @return void
	 */
	public function scanCategory(ArsTableCategory $a_category)
	{
		$this->setCategory($a_category);

		// Can't proceed without a category
		if (empty($this->category))
		{
			return;
		}

		// Can't proceed without a folder
		if (empty($this->folder))
		{
			return;
		}

		// Can't proceed if it's not a bleedingedge category
		if ($this->category->type != 'bleedingedge')
		{
			return;
		}

		// Get all releases in the category
		$allReleases = F0FModel::getTmpInstance('Releases', 'ArsModel')
							   ->category($this->category->id)
							   ->order('created')
							   ->dir('desc')
							   ->limitstart(0)
							   ->limit(0)
							   ->getItemList(true);

		// Check for possible use of Amazon S3
		$potentialPrefix = substr($this->category->directory, 0, 5);
		$potentialPrefix = strtolower($potentialPrefix);
		$useS3 = ($potentialPrefix == 's3://');

		JLoader::import('joomla.filesystem.folder');

		$known_folders = array();

		// Make sure published releases do exist
		if (!empty($allReleases))
		{
			foreach ($allReleases as $release)
			{
				if (!$release->published)
				{
					continue;
				}

				$mustScanFolder = true;

				if ($useS3)
				{
					$folder = $this->folder . '/' . $release->version;
					$known_folders[] = $release->version;
				}
				else
				{
					$folderName = $this->getReleaseFolder($this->folder, $release->version, $release->alias, $release->maturity);

					if ($folderName === false)
					{
						$mustScanFolder = false;
					}
					else
					{
						$known_folders[] = $folderName;
						$folder = $this->folder . '/' . $folderName;
					}
				}

				$exists = false;

				if ($mustScanFolder)
				{
					if ($useS3)
					{
						$check = substr($folder, 5);
						$s3 = ArsHelperAmazons3::getInstance();
						$items = $s3->getBucket('', $check . '/');
						$exists = !empty($items);
					}
					else
					{
						$exists = JFolder::exists($folder);
					}
				}

				if (!$exists)
				{
					$release->published = 0;

					$tmp = F0FModel::getTmpInstance('Releases', 'ArsModel')
								   ->getTable();
					$tmp->load($release->id);
					$tmp->save($release);
				}
				else
				{
					$tmpRelease = F0FModel::getTmpInstance('Releases', 'ArsModel')
										  ->getTable();
					$tmpRelease->bind($release);
					$this->checkFiles($tmpRelease);
				}
			}
			$first_release = array_shift($allReleases);
		}
		else
		{
			$first_release = null;
		}

		JLoader::import('joomla.filesystem.file');
		$first_changelog = array();

		if (!empty($first_release))
		{
			$changelog = $this->folder . '/' . $first_release->alias . '/CHANGELOG';

			$hasChangelog = false;

			if ($useS3)
			{
				$s3 = ArsHelperAmazons3::getInstance();
				$response = $s3->getObject(substr($changelog, 5));
				$hasChangelog = $response !== false;

				if ($hasChangelog)
				{
					$first_changelog = $response->body;
				}
			}
			else
			{
				if (JFile::exists($changelog))
				{
					$hasChangelog = true;
					$first_changelog = JFile::read($changelog);
				}
			}

			if ($hasChangelog)
			{
				if (!empty($first_changelog))
				{
					$first_changelog = explode("\n", str_replace("\r\n", "\n", $first_changelog));
				}
				else
				{
					$first_changelog = array();
				}
			}
		}

		// Get a list of all folders
		if ($useS3)
		{
			$allFolders = array();
			$everything = $this->_listS3Contents($this->folder);
			$dirLength = strlen($this->folder) - 5;

			if (count($everything))
			{
				foreach ($everything as $path => $info)
				{
					if (!array_key_exists('size', $info) && (substr($path, -1) == '/'))
					{
						if (substr($path, 0, $dirLength) == substr($this->folder, 5))
						{
							$path = substr($path, $dirLength);
						}

						$path = trim($path, '/');
						$allFolders[] = $path;
					}
				}
			}
		}
		else
		{
			$allFolders = JFolder::folders($this->folder);
		}

		if (!empty($allFolders))
		{
			foreach ($allFolders as $folder)
			{
				if (!in_array($folder, $known_folders))
				{
					// Create a new entry
					$notes = '';

					$changelog = $this->folder . '/' . $folder . '/' . 'CHANGELOG';

					$hasChangelog = false;

					if ($useS3)
					{
						$s3 = ArsHelperAmazons3::getInstance();
						$response = $s3->getObject(substr($changelog, 5));
						$hasChangelog = $response !== false;
						if ($hasChangelog)
						{
							$this_changelog = $response->body;
						}
					}
					else
					{
						if (JFile::exists($changelog))
						{
							$hasChangelog = true;
							$this_changelog = JFile::read($changelog);
						}
					}

					if ($hasChangelog)
					{
						if (!empty($this_changelog))
						{
							$notes = $this->coloriseChangelog($this_changelog, $first_changelog);
						}
					}
					else
					{
						$this_changelog = '';
					}

					JLoader::import('joomla.utilities.date');
					$jNow = new JDate();

					$alias = F0FStringUtils::toSlug($folder);

					$data = array(
						'id'          => 0,
						'category_id' => $this->category_id,
						'version'     => $folder,
						'alias'       => $alias,
						'maturity'    => 'alpha',
						'description' => '',
						'notes'       => $notes,
						'groups'      => $this->category->groups,
						'access'      => $this->category->access,
						'published'   => 1,
						'created'     => $jNow->toSql(),
					);

					// Before saving the release, call the onNewARSBleedingEdgeRelease()
					// event of ars plugins so that they have the chance to modify
					// this information.

					// -- Load plugins
					JLoader::import('joomla.plugin.helper');
					JPluginHelper::importPlugin('ars');

					// -- Setup information data
					$infoData = array(
						'folder'          => $folder,
						'category_id'     => $this->category_id,
						'category'        => $this->category,
						'has_changelog'   => $hasChangelog,
						'changelog_file'  => $changelog,
						'changelog'       => $this_changelog,
						'first_changelog' => $first_changelog
					);

					// -- Trigger the plugin event
					$app = JFactory::getApplication();
					$jResponse = $app->triggerEvent('onNewARSBleedingEdgeRelease', array(
						$infoData,
						$data
					));

					// -- Merge response
					if (is_array($jResponse))
					{
						foreach ($jResponse as $response)
						{
							if (is_array($response))
							{
								$data = array_merge($data, $response);
							}
						}
					}

					// -- Create the BE release
					$table = F0FModel::getTmpInstance('Releases', 'ArsModel')
									 ->getTable();
					if ($table->save($data, 'category_id'))
					{
						$this->checkFiles($table);
					}
				}
			}
		}
	}

	public function checkFiles(ArsTableRelease $release)
	{
		if (!$release->id)
		{
			throw new Exception('NO FUCKING WAY');
		}

		// Make sure we are give a release which exists
		if (empty($release->category_id))
		{
			return;
		}

		// Set the category from the release if the model's category doesn't match
		if (($this->category_id != $release->category_id) || empty($this->folder))
		{
			$this->setCategory($release->category_id);
		}

		// Make sure the category was indeed set
		if (empty($this->category) || empty($this->category_id) || empty($this->folder))
		{
			return;
		}

		// Make sure it is a Bleeding Edge category
		if ($this->category->type != 'bleedingedge')
		{
			return;
		}

		$potentialPrefix = substr($this->folder, 0, 5);
		$potentialPrefix = strtolower($potentialPrefix);
		$useS3 = ($potentialPrefix == 's3://');

		// Safe fallback
		$folderName = $release->version;

		if ($useS3)
		{
			// On S3 it's always the version-as-folder, otherwise it'd take FOREVER to scan S3
			$folder = $this->folder . '/' . $release->version;
			$known_folders[] = $release->version;
		}
		else
		{
			$folderName = $this->getReleaseFolder($this->folder, $release->version, $release->alias, $release->maturity);

			if ($folderName === false)
			{
				// Normally this shouldn't happen!
				return;
			}
			else
			{
				$known_folders[] = $folderName;
				$folder = $this->folder . '/' . $folderName;
			}
		}

		// Do we have a changelog?
		if (empty($release->notes))
		{
			$changelog = $folder . '/CHANGELOG';
			$hasChangelog = false;
			if ($useS3)
			{
				$s3 = ArsHelperAmazons3::getInstance();
				$response = $s3->getObject(substr($changelog, 5));
				$hasChangelog = $response !== false;
				if ($hasChangelog)
				{
					$this_changelog = $response->body;
				}
			}
			else
			{
				if (JFile::exists($changelog))
				{
					$hasChangelog = true;
					$this_changelog = JFile::read($changelog);
				}
			}

			if ($hasChangelog)
			{
				$first_changelog = array();
				$notes = $this->coloriseChangelog($this_changelog, $first_changelog);
				$release->notes = $notes;

				$table = F0FModel::getTmpInstance('Releases', 'ArsModel')
								 ->getTable()
								 ->save($release, 'category_id');
			}
		}

		$allItems = F0FModel::getTmpInstance('Items', 'ArsModel')
							->release($release->id)
							->limitstart(0)
							->getItemList(true);

		$known_items = array();

		if ($useS3)
		{
			$files = array();
			$everything = $this->_listS3Contents($folder);
			$dirLength = strlen($folder) - 5;
			if (count($everything))
			{
				foreach ($everything as $path => $info)
				{
					if (array_key_exists('size', $info) && (substr($path, -1) != '/'))
					{
						if (substr($path, 0, $dirLength) == substr($folder, 5))
						{
							$path = substr($path, $dirLength);
						}
						$path = trim($path, '/');
						$files[] = $path;
					}
				}
			}
		}
		else
		{
			$files = JFolder::files($folder);
		}

		if (!empty($allItems))
		{
			foreach ($allItems as $item)
			{
				$known_items[] = basename($item->filename);

				if ($item->published && !in_array(basename($item->filename), $files))
				{
					$table = clone F0FModel::getTmpInstance('Items', 'ArsModel')->getTable();
					$table->load($item->id);
					$table->save(array('published' => 0));
				}

				if (!$item->published && in_array(basename($item->filename), $files))
				{
					$table = F0FModel::getTmpInstance('Items', 'ArsModel')->getTable();
					$table->load($item->id);
					$table->save(array('published' => 1));
				}
			}
		}

		if (!empty($files))
		{
			foreach ($files as $file)
			{
				if (basename($file) == 'CHANGELOG')
				{
					continue;
				}

				if (in_array($file, $known_items))
				{
					continue;
				}

				JLoader::import('joomla.utilities.date');
				$jNow = new JDate();
				$data = array(
					'id'          => 0,
					'release_id'  => $release->id,
					'description' => '',
					'type'        => 'file',
					'filename'    => $folderName . '/' . $file,
					'url'         => '',
					'groups'      => $release->groups,
					'hits'        => '0',
					'published'   => '1',
					'created'     => $jNow->toSql(),
					'access'      => '1'
				);

				// Before saving the item, call the onNewARSBleedingEdgeItem()
				// event of ars plugins so that they have the chance to modify
				// this information.
				// -- Load plugins
				JLoader::import('joomla.plugin.helper');
				JPluginHelper::importPlugin('ars');
				// -- Setup information data
				$infoData = array(
					'folder'     => $folder,
					'file'       => $file,
					'release_id' => $release->id,
					'release'    => $release
				);
				// -- Trigger the plugin event
				$app = JFactory::getApplication();
				$jResponse = $app->triggerEvent('onNewARSBleedingEdgeItem', array(
					$infoData,
					$data
				));
				// -- Merge response
				if (is_array($jResponse))
				{
					foreach ($jResponse as $response)
					{
						if (is_array($response))
						{
							$data = array_merge($data, $response);
						}
					}
				}

				if (isset($data['ignore']))
				{
					if ($data['ignore'])
					{
						continue;
					}
				}

				$table = clone F0FModel::getTmpInstance('Items', 'ArsModel')->getTable();
				$table->reset();
				$result = $table->save($data);
			}
		}

		if (isset($table) && is_object($table) && method_exists($table, 'reorder'))
		{
			$table->reorder('`release_id` = ' . $release->id);
		}
	}

	private function coloriseChangelog(&$this_changelog, $first_changelog = array())
	{
		$this_changelog = explode("\n", str_replace("\r\n", "\n", $this_changelog));
		if (empty($this_changelog))
		{
			return '';
		}
		$notes = '';

		JLoader::import('joomla.application.component.helper');
		$params = JComponentHelper::getParams('com_ars');

		$generate_changelog = $params->get('begenchangelog', 1);
		$colorise_changelog = $params->get('becolorisechangelog', 1);

		if ($generate_changelog)
		{
			/**
			 * if($colorise_changelog) {
			 * $notes = '<h3>'.$changelog_header.'</h3>';
			 * }
			 * /**/

			$notes .= '<ul>';

			foreach ($this_changelog as $line)
			{
				if (in_array($line, $first_changelog))
				{
					continue;
				}
				if ($colorise_changelog)
				{
					$notes .= '<li>' . $this->colorise($line) . "</li>\n";
				}
				else
				{
					$notes .= "<li>$line</li>\n";
				}
			}
			$notes .= '</ul>';
		}

		return $notes;
	}

	private function colorise($line)
	{
		$line = trim($line);
		$line_type = substr($line, 0, 1);
		$style = '';
		switch ($line_type)
		{
			case '+':
				$style = 'added';
				$line = trim(substr($line, 1));
				break;
			case '-':
				$style = 'removed';
				$line = trim(substr($line, 1));
				break;
			case '#':
				$style = 'bugfix';
				$line = trim(substr($line, 1));
				break;
			case '~':
				$style = 'minor';
				$line = trim(substr($line, 1));
				break;
			case '!':
				$style = 'important';
				$line = trim(substr($line, 1));
				break;
			default:
				$style = 'default';
				break;
		}

		return "<span class=\"ars-devrelease-changelog-$style\">$line</span>";
	}

	private function _listS3Contents($path = null)
	{
		static $lastDirectory = null;
		static $lastListing = array();

		$directory = substr($path, 5);

		if ($lastDirectory != $directory)
		{
			if ($directory == '/')
			{
				$directory = null;
			}
			else
			{
				$directory = trim($directory, '/') . '/';
			}
			$s3 = ArsHelperAmazons3::getInstance();
			$lastListing = $s3->getBucket('', $directory, null, null, '/', true);
		}

		return $lastListing;
	}

	private function getReleaseFolder($folder, $version, $alias, $maturity)
	{
		$maturityLower = strtolower($maturity);
		$maturityUpper = strtoupper($maturity);

		$candidates = array(
			$alias,
			$version,
			$version . '_' . $maturityUpper,
			$version . '_' . $maturityLower,
			$alias . '_' . $maturityUpper,
			$alias . '_' . $maturityLower,
		);

		foreach ($candidates as $candidate)
		{
			$folderCheck = $folder . '/' . $candidate;

			if (JFolder::exists($folderCheck))
			{
				return $candidate;
			}
		}

		return false;
	}
}