<?php if ( ! defined( 'FW' ) ) {
    die( 'Forbidden' );
}

$manifest = array();

// Basic Info
$manifest['name']        = __( 'Backup & Demo Content', 'unysonplus' );
$manifest['slug']        = 'unysonplus-backup-content';
$manifest['description'] = __(
    'This extension lets you create an automated backup schedule, import demo content, or even create a demo content archive for migration purposes.',
    'unysonplus'
);

$manifest['version']     = '2.0.38';
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
