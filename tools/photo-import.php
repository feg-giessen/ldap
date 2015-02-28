<?php

function log_msg($message) {
    echo $message;
}

require('ldap_config.php');

if (!isset($config))
    die('No configuration set.');

require_once('class.ldapsync.php');
$ldapSync = new ldapsync($config, 'log_msg', E_ERROR);

// list all image files.
$count = $ldapSync->importPhotos(
    'dc=feg-giessen,dc=de',
    $config['img_base_path'] . "/" . $config['img_wildcard']);

log_msg("Imported $count jpeg photos.\n");
?>