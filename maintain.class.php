<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Installation, mise à jour et désinstallation du plugin.
 *
 * Piwigo ne charge pas main.inc.php pendant ces opérations (plugin inactif) : ce fichier est autonome,
 * les noms de tables sont recalculés ici avec $prefixeTable.
 *
 * Tables :
 *  - tag_manager_paths     : chemin hiérarchique de chaque tag (cache des fichiers) et groupes vides
 *  - tag_manager_homonyms  : tags ordinaires qui portent le nom d'une personne
 *  - tag_manager_jobs      : lots d'écriture (journal)
 *  - tag_manager_job_files : une ligne par photo d'un lot
 * Configuration : tag_manager_config.
 */
class tag_manager_maintain extends PluginMaintain
{
  private function tables()
  {
    global $prefixeTable;
    return array(
      'paths' => $prefixeTable . 'tag_manager_paths',
      'homonyms' => $prefixeTable . 'tag_manager_homonyms',
      'jobs' => $prefixeTable . 'tag_manager_jobs',
      'files' => $prefixeTable . 'tag_manager_job_files',
    );
  }

  /** Crée les tables absentes et ajoute les colonnes manquantes : peut être relancé sans risque. */
  function install($plugin_version, &$errors = array())
  {
    $t = $this->tables();

    pwg_query('
CREATE TABLE IF NOT EXISTS `' . $t['paths'] . '` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`            ENUM(\'tag\',\'group\') NOT NULL DEFAULT \'tag\',
  `tag_id`          INT UNSIGNED NULL,
  `path`            VARCHAR(500) NOT NULL,
  `sample_image_id` INT UNSIGNED NULL,
  `status`          VARCHAR(16) NOT NULL DEFAULT \'ok\',
  `indexed_at`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');

    pwg_query('
CREATE TABLE IF NOT EXISTS `' . $t['homonyms'] . '` (
  `tag_id`          INT UNSIGNED NOT NULL,
  `person_path`     VARCHAR(500) NOT NULL,
  `sample_image_id` INT UNSIGNED NULL,
  `indexed_at`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');

    pwg_query('
CREATE TABLE IF NOT EXISTS `' . $t['jobs'] . '` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `username`   VARCHAR(100) NOT NULL DEFAULT \'\',
  `status`     VARCHAR(12) NOT NULL DEFAULT \'pending\',
  `original`   TINYINT(1) NOT NULL DEFAULT 0,
  `total`      INT UNSIGNED NOT NULL DEFAULT 0,
  `done`       INT UNSIGNED NOT NULL DEFAULT 0,
  `errors`     INT UNSIGNED NOT NULL DEFAULT 0,
  `summary`    VARCHAR(255) NOT NULL DEFAULT \'\',
  `kind`       VARCHAR(10) NOT NULL DEFAULT \'tags\',
  `final`      MEDIUMTEXT NULL,
  PRIMARY KEY (`id`),
  KEY `st` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');

    // table créée par une version antérieure à 0.0.5, sans les colonnes des opérations de structure
    $cols = query2array('SHOW COLUMNS FROM `' . $t['jobs'] . '`;', 'Field', 'Field');
    if (!isset($cols['kind']))
    {
      pwg_query('ALTER TABLE `' . $t['jobs'] . '`
  ADD COLUMN `kind` VARCHAR(10) NOT NULL DEFAULT \'tags\',
  ADD COLUMN `final` MEDIUMTEXT NULL;');
    }

    pwg_query('
CREATE TABLE IF NOT EXISTS `' . $t['files'] . '` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`       INT UNSIGNED NOT NULL,
  `image_id`     INT UNSIGNED NOT NULL,
  `status`       VARCHAR(10) NOT NULL DEFAULT \'pending\',
  `claim`        VARCHAR(16) NULL,
  `claimed_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  `ops`          MEDIUMTEXT NOT NULL,
  `message`      VARCHAR(255) NOT NULL DEFAULT \'\',
  `processed_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `job_status` (`job_id`, `status`),
  KEY `claim` (`claim`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
  }

  /** Filet de sécurité : tables recréées si l'installation a été sautée. */
  function activate($plugin_version, &$errors = array())
  {
    $this->install($plugin_version, $errors);
  }

  function deactivate()
  {
  }

  /** Mise à jour d'une version à l'autre (Piwigo n'appelle pas activate() dans ce cas). */
  function update($old_version, $new_version, &$errors = array())
  {
    $this->install($new_version, $errors);
  }

  /** Aucune trace : les quatre tables et la configuration. Les fichiers des photos ne sont pas touchés. */
  function uninstall()
  {
    foreach ($this->tables() as $table)
    {
      pwg_query('DROP TABLE IF EXISTS `' . $table . '`;');
    }
    conf_delete_param('tag_manager_config');
  }
}
