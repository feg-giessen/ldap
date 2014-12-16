<?php

function log_msg($message) {
    echo $message;
}

require('ldap_config.php');

if (isset($config))
    die('No configuration set.');

$ldap_conn = ldap_connect($config['ldap_server'], $config['ldap_port']);
ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);

$success = ldap_bind($ldap_conn , $config['ldap_user'], $config['ldap_pw']);
if (!$success) {
    log_msg('LDAP fehlgeschlagen');
    exit;
}

// list all image files.
$files = glob($config['img_base_path'] . "/*.jpg");

foreach($files as $path) {
    $index = strrpos($path, '/');
    $file = $index > 0 ? substr($path, $index + 1) : $path;
    log_msg($file . "\n");

    $index = strrpos($file, ".");
    $name = ltrim(substr($file, 0, $index), "0");
    $ext = substr($file, $index + 1);

    if ($ext !== "jpg")
        continue;

    $uid = intval($name);
    if ($uid != $name)
        continue;

    $filter = '(&(objectClass=fegperson)(syncUserId=' . $uid .'))';
    $sr = ldap_search($ldap_conn, 'dc=feg-giessen,dc=de', $filter);

    $info = array();
    $ldap_res = null;
    if ($sr !== false) {
        $info = ldap_get_entries($ldap_conn, $sr);
        $ldap_res = ldap_first_entry($ldap_conn, $sr);
        ldap_free_result($sr);
    }

    if ($info['count'] && $info['count'] != 0) {
        $dn = ldap_get_dn($ldap_conn, $ldap_res);

        $f = fopen($path, 'r');
        $entry = array(
            'jpegPhoto' => fread($f, filesize($path))
        );
        fclose($f);

        $success = ldap_modify(
            $ldap_conn,
            $dn,
            $entry);

        if (!$success) {
            log_msg("Error updating $dn\n" . print_r($entry, true) . "\n");
        } else {
            log_msg("Updated $dn\n");
        }
    }
}

ldap_close($ldap_conn);

?>