<?php

require('ldap_config.php');

if (isset($config))
    die('No configuration set.');

function ssha_encode($text) {
    $salt = "";
    for ($i=1; $i<=10; $i++) {
        $salt .= substr('0123456789abcdef', rand(0, 15), 1);
    }
    $hash = "{SSHA}" . base64_encode(pack("H*", sha1($text . $salt)) . $salt);
    return $hash;
}

function splitInts($csv) {
    $values = explode(',', $csv);

    $result = array();
    foreach ($values as $v) {
        $t = trim($v);
        if (!empty($t)) {
            $i = intVal($t);

            if ($i == $t) {
                $result[] = $i;
            }
        }
    }

    return $result;
}

function log_msg($message) {
    echo $message;
}

function sanitizeSingleLine($str) {
    $parts = preg_split("/$/m", $str);
    return is_array($parts) && count($parts) > 0 ? $parts[0] : $str;
}

function strEndsWith($target, $test) {
    $len_test = strlen($test);

    return (strlen($target) >= $len_test)
        && (strcasecmp(substr($target, -1 * $len_test), $test) === 0);
}

if (function_exists("ldap_escape") === false)
{
    /**
     * function ldap_escape
     * @source http://stackoverflow.com/questions/8560874/php-ldap-add-function-to-escape-ldap-special-characters-in-dn-syntax#answer-8561604
     * @author Chris Wright
     * @version 2.0
     * @param string $subject The subject string
     * @return string The escaped string
     */
    function ldap_escape($subject)
    {
        // The base array of characters to escape
        // Flip to keys for easy use of unset()
        $search = array_flip(array('\\', '*', '(', ')', "\x00"));

        // Flip $search back to values and build $replace array
        $search = array_keys($search);
        $replace = array();
        foreach ($search as $char) {
            $replace[] = sprintf('\\%02x', ord($char));
        }

        // Do the main replacement
        $result = str_replace($search, $replace, $subject);

        return $result;
    }
}

$db_handle = mysql_connect($config['db_server'], $config['db_user'], $config['db_pw']);
$db_found = mysql_select_db($config['db_name'], $db_handle);

if (!$db_found) {
    log_msg('Database NOT Found.');
    mysql_close($db_handle);
    exit;
}

$ldap_conn = ldap_connect($config['ldap_server'], $config['ldap_port']);
ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);

$success = ldap_bind($ldap_conn , $config['ldap_user'], $config['ldap_pw']);
if (!$success) {
    log_msg('LDAP fehlgeschlagen');
    ldap_close($ldap_conn);
    exit;
}

$groups = array();

$filter = '(objectClass=groupOfNames)';
$group_dn = 'ou=gruppen,dc=feg-giessen,dc=de';
$sr = ldap_search($ldap_conn, $group_dn, $filter);

// get groups with syncGroupId from LDAP
$info = array();
if ($sr !== false) {
    $info = ldap_get_entries($ldap_conn, $sr);

    ldap_free_result($sr);

    for($i = 0; $i < $info['count']; $i++) {

        $group_name = $info[$i]['cn'][0];

        if (!array_key_exists('syncgroupid', $info[$i])) {
            continue;
        }

        $group_syncid = intval($info[$i]['syncgroupid'][0]);

        if (isset($groups[$group_syncid])) {
            log_msg("Group with id $group_syncid already defined.");
        } else {
            $groups[$group_syncid] = array(
                'cn' => $group_name,
                'members' => array(),
                'origMembers' => isset($info[$i]['member']) ? $info[$i]['member'] : null
            );

            if (is_array($groups[$group_syncid]['origMembers'])) {
                unset($groups[$group_syncid]['origMembers']['count']);
                $groups[$group_syncid]['origMembers'] = array_values($groups[$group_syncid]['origMembers']);
            } else {
                $groups[$group_syncid]['origMembers'] = array();
            }
        }
    }

    unset($info);
}

// import optigem groups
$sql = "SELECT optigem_id, name FROM og_categorys";
$result = mysql_query($sql);

while ($db_field = mysql_fetch_assoc($result)) {
    $groupId = intval($db_field['optigem_id']);
    $name = $db_field['name'];

    // check group exists in ldap
    if(isset($groups[$groupId])) {
        // compare name, rename
        $name_old = $groups[$groupId]['cn'];
        if (strcasecmp($name_old, $name) !== 0) {
            log_msg("Renaming group $name... (old: $name_old)\n");
            ldap_rename($ldap_conn, "cn=$name_old,$group_dn", $name, $group_dn, true);
            $groups[$groupId]['cn'] = $name;
        }
    } else {
        // create group in ldap
        log_msg("Adding group... $name \n");

        $entry = array(
            'objectClass' => array('top', 'groupOfNames', 'feggroup'),
            'cn' => $name,
            'syncGroupId' => $groupId
        );

        $success = ldap_add($ldap_conn, $group_dn, $entry);
        if (!$success) {
            log_msg("Error adding group $name\n" . print_r($entry, true) . "\n");
        } else {
            $groups[$groupId] = array(
                'cn' => $name,
                'members' => array(),
                'origMembers' => array()
            );
        }
    }
}
mysql_free_result($result);

$sql = "SELECT * FROM fe_users ORDER BY name";
$result = mysql_query($sql);

while ($db_field = mysql_fetch_assoc($result)) {
    $cn = utf8_encode(str_replace('  ', ' ', str_replace(',', '', $db_field['username'])));
    $mail = $db_field['email'];
    $uid = intval($db_field['uid']);

    $disable = intval($db_field['disable']) === 1;
    $starttime = intval($db_field['starttime']);
    $endtime = intval($db_field['endtime']);

    $inactive = $disable || ($starttime !== 0 && $starttime > time()) || ($endtime !== 0 && $endtime < time());

    $ou = $inactive ? 'inaktiv' : 'mitglieder,ou=benutzer';
    $required_ou_base = $inactive ? 'ou=inaktiv,dc=feg-giessen,dc=de' : 'ou=benutzer,dc=feg-giessen,dc=de';
    $dn = 'cn=' . $cn . ',ou=' . $ou . ',dc=feg-giessen,dc=de';

    $filter = '(&(objectClass=inetOrgPerson)(|(syncUserId=' . $uid .')(cn=' . ldap_escape($cn) . ')))';
    $sr = ldap_search($ldap_conn, 'dc=feg-giessen,dc=de', $filter);

    $info = array();
    $ldap_res = null;
    if ($sr !== false) {
        $info = ldap_get_entries($ldap_conn, $sr);
        $ldap_res = ldap_first_entry($ldap_conn, $sr);
        ldap_free_result($sr);
    }

    if (!$info['count'] || $info['count'] == 0) {
        log_msg("Adding... $cn \n");

        $entry = array(
            'objectClass' => array('top', 'person', 'organizationalPerson', 'inetOrgPerson', 'fegperson', 'simpleSecurityObject'),
            'cn' => $cn,
            'syncUserId' => $uid,
            'userPassword' => ssha_encode($db_field['password'])
        );

        $success = ldap_add($ldap_conn, $dn, $entry);
        if (!$success) {
            log_msg("Error adding $cn\n" . print_r($entry, true) . "\n");
        }
    } else {
        $old_dn = ldap_get_dn($ldap_conn, $ldap_res);

        if (strEndsWith($old_dn, $required_ou_base) === false) {
            log_msg("Moving to correct OU... (old: $old_dn)\n");
            list($new_rdn, $new_parent) = explode(',', $dn, 2);
            ldap_rename($ldap_conn, $old_dn, $new_rdn, $new_parent, true);
        } else {
            // overwrite assumed dn with actual dn (might be differently cased).
            $dn = $old_dn;
        }
    }

    // Check if username was renamed.
    $old_cn = $info[0]['cn'][0];
    if ($old_cn !== $cn) {
        $old_dn = ldap_get_dn($ldap_conn, $ldap_res);
        list(,$new_parent) = explode(',', $old_dn, 2);
        ldap_rename($ldap_conn, $old_dn, 'cn=' . $cn, $new_parent, true);
    }

    if (!$inactive) {
        $user_groups = splitInts($db_field['og_categorys']);

        foreach ($user_groups as $g) {
            if (isset($groups[$g])) {
                $groups[$g]['members'][] = $dn;
            }
        }
    }

    $entry = array(
        'displayname' => $db_field['name'],
        'typo3disabled' => $disable ? 'TRUE' : 'FALSE',

        'street' => $db_field['address'],
        'l' => $db_field['city'],
        'postalcode' => $db_field['zip'],

        'telephonenumber' => sanitizeSingleLine($db_field['telephone']),
        'facsimiletelephonenumber' => sanitizeSingleLine($db_field['fax']),

        'mail' => $mail
    );

    $dateOfBirth = intval($db_field['date_of_birth']);
    if ($dateOfBirth !== 0) {
        $entry['dateofbirth'] = date("Ymd", $dateOfBirth);
    } else if (isset($info[0]['dateofbirth'])) {
        $entry['dateofbirth'] = array();
    }

    if ($starttime !== 0) {
        $entry['startdate'] = date("YmdHis", $starttime) . "Z";
    } else if (isset($info[0]['startdate'])) {
        $entry['startdate'] = array();
    }

    if ($endtime !== 0) {
        $entry['enddate'] = date("YmdHis", $endtime) . "Z";
    } else if (isset($info[0]['enddate'])) {
        $entry['enddate'] = array();
    }

    $country = $db_field['country'];
    if ($country == '') {
        $entry['c'] = $country;
    } else {
        $entry['c'] = 'Germany';
    }

    foreach ($entry as $k => $v) {
        if (empty($v)) {
            if (isset($info[0][$k])) {
                $entry[$k] = array();
            } else {
                unset($entry[$k]);
            }
        } else {
            $entry[$k] = utf8_encode($v);
            if (isset($info[0][$k]) && $info[0][$k][0] == $entry[$k]) {
                unset($entry[$k]);
            }
        }
    }

    if (count($entry) > 0) {
        log_msg("Updating $cn\n " . print_r($entry, true));

        $success = ldap_modify(
            $ldap_conn,
            $dn,
            $entry);

        if (!$success) {
            log_msg("Error updating $cn\n" . ldap_error($ldap_conn) . "\n" . print_r($entry, true) . "\n");
        }
    }
}

foreach ($groups as $group) {

    $group_dn = 'cn=' . $group['cn'] . ',ou=gruppen,dc=feg-giessen,dc=de';

    $new_members = is_array($group['members']) ? array_diff($group['members'], $group['origMembers']) : array();
    $removed_members = is_array($group['members']) ? array_diff($group['origMembers'], $group['members']) : $group['origMembers'];

    if (count($new_members) > 0) {

        log_msg("New group members for " . $group['cn'] . "\n" . print_r($new_members, true));

        ldap_mod_add(
            $ldap_conn,
            $group_dn,
            array('member' => array_values($new_members)));
    }

    if (count($removed_members) > 0) {

        log_msg("Deleted group members for " . $group['cn'] . "\n" . print_r($removed_members, true));

        ldap_mod_del(
            $ldap_conn,
            $group_dn,
            array('member' => array_values($removed_members)));
    }
}

mysql_close($db_handle);
ldap_close($ldap_conn);

?>