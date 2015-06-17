<?php

// Report simple running errors
error_reporting(E_ERROR);

require('ldap_config.php');

if (!isset($config))
    die('No configuration set.');

function log_msg($message) {
    echo $message;
}

putenv('LDAPTLS_REQCERT=never');

require_once('class.ldapsync.php');
$ldapSync = new ldapsync($config, 'log_msg', $config['logLevel']);

$ldapSync->syncTypo3Users('dc=feg-giessen,dc=de');

$ldapSync->close();

?>