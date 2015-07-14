<?php

// Report simple running errors
error_reporting(E_ERROR);

function log_msg($message) {
    echo $message;
}

require('ldap_config.php');

if (!isset($config))
    die('No configuration set.');

putenv('LDAPTLS_REQCERT=never');

require_once('class.ldapsync.php');
$ldapSync = new ldapsync($config, 'log_msg', $config['logLevel']);

// list all image files.
$count = $ldapSync->importPhotos(
    'dc=feg-giessen,dc=de',
    '(&(objectclass=fegperson))',
    $config['img_base_path'] . "/" . $config['img_wildcard']);

// log_msg("Imported $count jpeg photos.\n");
?>