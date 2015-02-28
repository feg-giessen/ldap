<?php

require('ldap_config.php');

if (!isset($config))
    die('No configuration set.');

function log_msg($message) {
    echo $message;
}

require_once('class.ldapsync.php');
$ldapSync = new ldapsync($config, 'log_msg', E_ERROR);

const GROUP_DN = 'ou=gruppen,dc=feg-giessen,dc=de';
$groups = $ldapSync->getLdapSyncGroups('groupOfNames', GROUP_DN);

$ogCategories = $ldapSync->getOptigemCategories();
$ldapSync->importOptigemCategories($groups, $ogCategories, GROUP_DN);

$ldapSync->syncUsers($groups, 'ou=benutzer', 'ou=inaktiv', 'dc=feg-giessen,dc=de');

foreach ($groups as $source => $groupList) {
    $ldapSync->syncGroupData($groupList, GROUP_DN);
}

$ldapSync->close();

?>