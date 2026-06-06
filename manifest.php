<?php if ( ! defined( 'FW' ) ) {
    die( 'Forbidden' );
}

/**
 * Changelog ----------------------------------------------------------------
 *
 * 2.0.41 - Selective backup, automatic cleanup, and backup upload. You can now
 *          un-check individual top-level Plugins / Themes / Uploads folders to
 *          exclude them from backups (Plugins and Themes apply to full backups,
 *          Uploads applies to both) via a new collapsible "Selective Backup &
 *          Cleanup" panel on the Backup page; the choices are stored in the
 *          'fw:ext:backups:excluded_dirs' option and fed into the files-export
 *          task exclude list, so they apply to manual and scheduled backups
 *          alike. A "keep last N" setting auto-deletes older archives after each
 *          new backup (runs on the zip task success hook; 0 = keep all). And a
 *          new Upload control lets you add a .zip backup created by this
 *          extension straight into the archives list so it can be restored
 *          (validated to be a real backup archive before being stored).
 */

$manifest = array();

// Basic Info
$manifest['name']        = __( 'Backup & Demo Content', 'unysonplus' );
$manifest['slug']        = 'unysonplus-backup-content';
$manifest['description'] = __(
    'This extension lets you create an automated backup schedule, import demo content, or even create a demo content archive for migration purposes.',
    'unysonplus'
);

$manifest['version']     = '2.0.41';
$manifest['display']     = true;
$manifest['standalone']  = true;

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Backups-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-Backups-Extension';
$manifest['github_branch'] = 'master';
$manifest['uri']           = 'http://manual.unyson.io/en/latest/extension/backups/index.html';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Requirements
$manifest['requirements'] = array(
    'framework' => array(
        'min_version' => '2.6.16',
    ),
);

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'unysonplus';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
