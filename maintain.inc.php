<?php

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

// Table des chemins hiérarchiques : une ligne par tag de dernier niveau (kind='tag'),
// plus une ligne par groupe vide créé dans le plugin (kind='group', tag_id NULL).
// Le chemin complet d'un tag n'existe que dans les fichiers : cette table sert de cache.

function plugin_install($plugin_id = null, $plugin_version = null, &$errors = array())
{
  global $prefixeTable;

  pwg_query('
CREATE TABLE IF NOT EXISTS `' . $prefixeTable . 'tag_manager_paths` (
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
}

function plugin_activate($plugin_id = null, $plugin_version = null, &$errors = array())
{
  // Filet de sécurité : crée la table si l'installation a été sautée
  plugin_install($plugin_id, $plugin_version, $errors);
}

function plugin_deactivate()
{
}

function plugin_uninstall($plugin_id = null)
{
  global $prefixeTable;

  pwg_query('DROP TABLE IF EXISTS `' . $prefixeTable . 'tag_manager_paths`;');
  conf_delete_param('tag_manager_config');
}

?>
