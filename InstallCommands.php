<?php

namespace Drush\Commands\tome_drush;

use Drupal\Component\FileCache\FileCacheFactory;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Extension\InfoParserDynamic;
use Drupal\Core\Site\Settings;
use Drush\Commands\DrushCommands;
use Drush\Drupal\ExtensionDiscovery;

/**
 * Contains install and init commands for tome.
 */
class InstallCommands extends DrushCommands {

  /**
   * Installs tome.
   *
   * @bootstrap configuration
   * @command tome:install
   *
   * @return int|null
   *   The status code, if the command did not complete successfully.
   */
  public function install() {
    if (!$this->io()->confirm('You are about to DROP all tables in your local database and re-install Tome. Do you want to continue?', FALSE)) {
      return 0;
    }

    FileCacheFactory::setConfiguration(['default' => ['class' => '\Drupal\Component\FileCache\NullFileCache']]);
    $source_storage = new FileStorage(config_get_config_directory(CONFIG_SYNC_DIRECTORY));

    if (!$source_storage->exists('core.extension')) {
      $this->io()->warning('Existing configuration to install from not found. If this is your first time using Tome try running "drush tome:init".');
      return 1;
    }

    $config = $source_storage->read('core.extension');

    drush_invoke_process('@self', 'site-install', [$config['profile']], ['yes' => TRUE]);
    if (drush_get_error()) return 1;
    drush_invoke_process('@self', 'pm:enable', ['tome'], ['yes' => TRUE]);
    if (drush_get_error()) return 1;
    drush_invoke_process('@self', 'tome:import', [], ['yes' => TRUE]);
    if (drush_get_error()) return 1;
    drush_invoke_process('@self', 'cache:rebuild', [], ['yes' => TRUE]);
    if (drush_get_error()) return 1;
  }

  /**
   * Initializes tome.
   *
   * @bootstrap configuration
   * @command tome:init
   *
   * @return int|null
   *   The status code, if the command did not complete successfully.
   */
  public function init() {
    if (is_dir(config_get_config_directory(CONFIG_SYNC_DIRECTORY)) || is_dir(Settings::get('tome_content_directory', '../content'))) {
      if (!$this->io()->confirm('Running this command will remove all exported content and configuration. Do you want to continue?', FALSE)) {
        return 0;
      }
    }

    $profiles = $this->getProfiles();
    $profile = $this->io()->choice('Select an installation profile', $profiles);
    drush_invoke_process('@self', 'site-install', [$profile], ['yes' => TRUE]);
    if (drush_get_error()) return 1;
    drush_invoke_process('@self', 'pm:enable', ['tome'], ['yes' => TRUE]);
    if (drush_get_error()) return 1;
    drush_invoke_process('@self', 'tome:export', [], ['yes' => TRUE]);
    if (drush_get_error()) return 1;
  }

  /**
   * Gets a list of profiles.
   *
   * @return string[]
   *   An array of profile descriptions keyed by the profile machine name.
   */
  protected function getProfiles() {
    // Build a list of all available profiles.
    $listing = new ExtensionDiscovery(getcwd(), FALSE);
    $listing->setProfileDirectories([]);
    $profiles = [];
    $info_parser = new InfoParserDynamic();
    foreach ($listing->scan('profile') as $profile) {
      $details = $info_parser->parse($profile->getPathname());
      // Don't show hidden profiles.
      if (!empty($details['hidden'])) {
        continue;
      }
      // Determine the name of the profile; default to the internal name if none
      // is specified.
      $name = isset($details['name']) ? $details['name'] : $profile->getName();
      $description = isset($details['description']) ? "$name - {$details['description']}" : $name;
      $profiles[$profile->getName()] = $description;
    }
    natcasesort($profiles);
    return $profiles;
  }

}
