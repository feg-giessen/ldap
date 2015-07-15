<?php
/**
 * Created by PhpStorm.
 * User: Peter Schuster
 * Date: 27.02.2015
 * Time: 15:23
 */
 
 if (!defined('JSON_PRETTY_PRINT')) {
	define('JSON_PRETTY_PRINT', 0);
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

class ldapsync {

    /**
     * @var mysqli
     */
    private $db;

    private $ldapConnection;

    private $logging_callback;

    private $log_level;

    /**
     * @param array $config
     * @param string|array $logging_callback
     * @param int $log_level
     */
    function __construct(array $config, $logging_callback, $log_level) {
        $this->logging_callback = $logging_callback;
        $this->log_level = $log_level;

        $this->db = $this->initializeDatabase($config);
        $this->ldapConnection = $this->initializeLdap($config);
    }

    /**
     * @param string $message
     * @param int $log_level
     */
    private function log_msg($message, $log_level) {
        if ($log_level > $this->log_level)
            return;

        if (!$this->strEndsWith($message, "\n")) {
            $message = $message . "\n";
        }

        call_user_func($this->logging_callback, $message);
    }

    /**
     * @param array $config
     * @return mysqli
     */
    private function initializeDatabase(array $config) {
        if (!isset($config['db_server']))
            return null;

        $db_handle = new mysqli($config['db_server'], $config['db_user'], $config['db_pw'], $config['db_name']);

        if ($db_handle->connect_error) {
            $this->log_msg('Database NOT Found: ' . $db_handle->connect_error, E_ERROR);
            die('Database NOT Found: ' . $db_handle->connect_error);
        }

        return $db_handle;
    }

    /**
     * @param array $config
     * @return resource
     */
    private function initializeLdap(array $config) {
        if (!isset($config['ldap_server']))
            return null;

        $ldap_conn = ldap_connect($config['ldap_server'], $config['ldap_port']);
        ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);

        $success = ldap_bind($ldap_conn , $config['ldap_user'], $config['ldap_pw']);
        if (!$success) {
            $this->log_msg('LDAP connection failed: ' . ldap_error($ldap_conn), E_ERROR);
            ldap_close($ldap_conn);
            die("LDAP connection failed\n");
        }

        return $ldap_conn;
    }

    /**
     * @param string $objectClass
     * @param string $dn
     * @return array
     */
    public function getLdapSyncGroups($objectClass, $dn) {

        $groups = array();

        $filter = '(objectClass=' . $objectClass . ')';
        $sr = ldap_search($this->ldapConnection, $dn, $filter);

        // get groups with syncGroupId from LDAP
        $info = null;
        if ($sr !== false) {
            $info = ldap_get_entries($this->ldapConnection, $sr);
            ldap_free_result($sr);

            for($i = 0; $i < $info['count']; $i++) {

                $group_name = $info[$i]['cn'][0];

                if (!array_key_exists('syncgroupid', $info[$i])) {
                    continue;
                }

                $groupSyncId = intval($info[$i]['syncgroupid'][0]);
                $groupSyncSource = $info[$i]['syncgroupsource'][0];
                if (empty($groupSyncSource)) {
                    $groupSyncSource = 'all';
                }

                if (!isset($groups[$groupSyncSource])) {
                    $groups[$groupSyncSource] = array();
                }

                if (isset($groups[$groupSyncSource][$groupSyncId])) {
                    $this->log_msg("Group with id $groupSyncId already defined.", E_WARNING);
                } else {
                    $group = array(
                        'cn' => $group_name,
                        'members' => array(),
                        'origMembers' => isset($info[$i]['member']) ? $info[$i]['member'] : null
                    );

                    if (is_array($group['origMembers'])) {
                        unset($group['origMembers']['count']);
                        $group['origMembers'] = array_values($group['origMembers']);
                    } else {
                        $group['origMembers'] = array();
                    }

                    $groups[$groupSyncSource][$groupSyncId] = $group;
                }
            }

            unset($info);
        }

        return $groups;
    }

    /**
     * @return array
     */
    public function getOptigemCategories() {
        $sql = "SELECT optigem_id, name FROM og_categorys";

        /** @var mysqli_result $result */
        $result = $this->db->query($sql);

        $ogCategories = array();

        if ($result === false) {
            $this->log_msg($this->db->error, E_ERROR);
            return array();
        }

        while ($db_field = $result->fetch_assoc()) {
            $groupId = intval($db_field['optigem_id']);
            $name = $db_field['name'];

            $ogCategories[$groupId] = array(
                'id' => $groupId,
                'name' => $name,
                'syncgroupsource' => 'og_categories'
            );
        }
        $result->free();

        return $ogCategories;
    }

    /**
     * @param array $ldapGroups
     * @param array $ogCategories
     * @param string $groupDn
     * @param array $defaultParentGroups
     */
    public function importOptigemCategories(array &$ldapGroups, array $ogCategories, $groupDn, array $defaultParentGroups) {
        $newGroups = array();

        foreach($ogCategories as $id => $item) {
            // check group exists in ldap
            if(isset($ldapGroups['all'][$id])) {
                // compare name, rename
                $group = $ldapGroups['all'][$id]['cn'];
                $group['cn'] = $this->renameGroup($group, $item['name'], $groupDn);
            } else if (isset($ldapGroups['og_categories'][$id])) {
                // compare name, rename
                $group = $ldapGroups['og_categories'][$id];
                $group['cn'] = $this->renameGroup($group, $item['name'], $groupDn);
            } else {
                // create group in ldap
                $name = utf8_encode(str_replace('  ', ' ', str_replace(',', '', $item['name'])));
                $this->log_msg("Adding group... $name \n", E_NOTICE);

                $entry = array(
                    'objectclass' => array('top', 'groupOfNames', 'feggroup'),
                    'cn' => $name,
                    'syncgroupid' => $id,
                    'syncgroupsource' => 'og_categories'
                );

                $dn = "cn=$name,$groupDn";
                $success = ldap_add($this->ldapConnection, $dn, $entry);
                if (!$success) {
                    $this->log_msg("Error adding group $name\n" . json_encode($entry, JSON_PRETTY_PRINT) . "\n", E_ERROR);
                } else {
                    $newGroups[] = $dn;
                    $ldapGroups['og_categories'][$id] = array(
                        'cn' => $name,
                        'members' => array(),
                        'origMembers' => array()
                    );
                }
            }
        }

        // Add new optigem group to list of default groups.
        if (is_array($defaultParentGroups) && count($defaultParentGroups) > 0 && count($newGroups) > 0) {

            foreach ($defaultParentGroups as $group) {
                $group_info['member'] = $newGroups;
                ldap_mod_add($this->ldapConnection, $group, $group_info);
            }
        }
    }

    public function syncTypo3Users($baseDn) {
        // Get all ldap groups from TYPO3
        $sql = "SELECT uid,tx_igldapssoauth_dn from fe_groups WHERE tx_igldapssoauth_dn IS NOT NULL AND tx_igldapssoauth_dn != ''";
        $result = $this->db->query($sql);
        $groups = array();

        while ($db_field = $result->fetch_assoc()) {
            $groups[utf8_encode($db_field['tx_igldapssoauth_dn'])] = intval($db_field['uid']);
            $this->log_msg('Found group ' . $db_field['tx_igldapssoauth_dn'], E_NOTICE);
        }

        $result->free();
        $result->close();

        $sql = "SELECT * from fe_users WHERE ldap_entryuuid IS NOT NULL AND ldap_entryuuid != ''";
        $result = $this->db->query($sql);

        //
        // Update 'DN' fields and 'usergroup' fields in TYPO3 database.

        $sql = 'UPDATE fe_users SET tx_igldapssoauth_dn=? WHERE uid=?';
        $updateStatement = $this->db->prepare($sql);

        $sql = 'UPDATE fe_users SET usergroup=? WHERE uid=?';
        $updateStatementGroups = $this->db->prepare($sql);

        while ($db_field = $result->fetch_assoc()) {
            $typo3User = new user($db_field);

            $entryuuid = $db_field['ldap_entryuuid'];
            $ldap_entry = null;
            $matchedDn = $this->getLdapUserFilter($baseDn, $ldap_entry, "(&(entryuuid=$entryuuid))", array('cn', 'memberof'));

            if ($ldap_entry != null) {
                $updateStatement->bind_param('si', utf8_decode($matchedDn), $typo3User->uid);
                $updateStatement->execute();
                $updateStatement->reset();

                $this->log_msg('Updated entryUUID for ' . $typo3User->cn, E_NOTICE);

                if (array_key_exists('memberof', $ldap_entry)) {
                    $typo3Groups = $typo3User->getUserGroups();

                    foreach ($ldap_entry['memberof'] as $key => $groupDn) {
                        if (array_key_exists($groupDn, $groups)) {
                            $typo3Groups[] = $groups[$groupDn];
                        }
                    }

                    $typo3Groups = array_unique($typo3Groups);

                    $updateStatementGroups->bind_param('si', implode(',', $typo3Groups), $typo3User->uid);
                    $updateStatementGroups->execute();
                    $updateStatementGroups->reset();

                    $this->log_msg('Updated groups for ' . $typo3User->cn, E_NOTICE);
                }
            }

        }

        $updateStatement->close();
        $updateStatementGroups->close();

        $result->free();
        $result->close();
    }

    /**
     * @param string $baseDn
     * @param array $entry
     * @param string $filter
     * @param array $parameters
     * @return null|string  full dn
     */
    private function getLdapUserFilter($baseDn, &$entry, $filter, array $parameters = null) {
        $sr = ldap_search($this->ldapConnection, $baseDn, $filter, $parameters);

        $info = null;
        $ldap_res = null;
        if ($sr !== false) {
            $info = ldap_get_entries($this->ldapConnection, $sr);
            $ldap_res = ldap_first_entry($this->ldapConnection, $sr);
            ldap_free_result($sr);

            if ($info['count'] && $info['count'] !== 0) {
                $entry = $info[0];
                return ldap_get_dn($this->ldapConnection, $ldap_res);
            }
        }

        $entry = null;
        return null;
    }

    /**
     * @return mysqli_result
     */
    private function getUsers() {
        $sql = "SELECT * FROM fe_users ORDER BY name";
        $result = $this->db->query($sql);

        return $result;
    }

    /**
     * @param string $baseDn
     * @param int $uid
     * @param array $entry
     * @return null|string  full dn
     */
    private function getLdapUser($baseDn, $uid, &$entry, $objectClass='inetOrgPerson') {
        $filter = "(&(objectClass=$objectClass)(syncUserId=$uid))";
        $sr = ldap_search($this->ldapConnection, $baseDn, $filter);

        $info = null;
        $ldap_res = null;
        if ($sr !== false) {
            $info = ldap_get_entries($this->ldapConnection, $sr);
            $ldap_res = ldap_first_entry($this->ldapConnection, $sr);
            ldap_free_result($sr);

            if ($info['count'] && $info['count'] !== 0) {
                $entry = $info[0];
                return ldap_get_dn($this->ldapConnection, $ldap_res);
            }
        }

        $entry = null;
        return null;
    }

    /**
     * @param string $baseDn
     * @param $filter
     * @param array $attributes
     * @return null|string full dn
     */
    private function getLdapUsers($baseDn, $filter, array $attributes) {
        $sr = ldap_search($this->ldapConnection, $baseDn, $filter, $attributes);

        $info = null;
        $ldap_res = null;
        $users = array();
        if ($sr !== false) {
            $info = ldap_get_entries($this->ldapConnection, $sr);

            if ($info['count'] && $info['count'] !== 0) {
                foreach ($info as $index => $entry) {
                    if (is_array($entry)) {
                        if (isset($entry['count'])) {
                            unset($entry['count']);
                        }

                        foreach ($entry as $key => $value) {
                            if (isset($value['count'])) {
                                unset($entry[$key]['count']);
                            }
                        }

                        $users[$entry['entryuuid']['0']] = $entry;
                    }
                }
            }

            ldap_free_result($sr);
        }

        return $users;
    }

    /**
     * @param array $ldapUser
     * @param string $jpegPath
     * @return bool success
     */
    public function setUserPhoto(array $ldapUser, $jpegPath) {
        if ($ldapUser === null)
            return false;

        $fileTime = filemtime($jpegPath);

        // Skip file if not changed...
        if (isset($ldapUser['phototimestamp']) && isset($ldapUser['phototimestamp'][0])) {
            $timestamp = $this->getTimestamp($ldapUser['phototimestamp'][0]);

            if ($timestamp >= $fileTime)
                return false;
        }

        $f = fopen($jpegPath, 'r');
        $entry = array(
            'jpegPhoto' => fread($f, filesize($jpegPath)),
            'phototimestamp' => $this->getLdapDate($fileTime),
        );
        fclose($f);

        $user_dn = $ldapUser['dn'];

        $success = ldap_modify(
            $this->ldapConnection,
            $user_dn,
            $entry);

        if (!$success) {
            $this->log_msg("Error setting photo for $user_dn\n" . json_encode($entry, JSON_PRETTY_PRINT) . "\n", E_ERROR);
        } else {
            $this->log_msg("Set photo for $user_dn\n", E_USER_NOTICE);
        }

        return $success;
    }

    public function importPhotos($baseDn, $allLdapUsersFilter, $jpegSearchPath) {
        $count = 0;

        // Get all fe_users records for entryUUID resolsution

        $sql = "SELECT uid,ldap_entryuuid from fe_users WHERE NOT (ldap_entryuuid IS NULL)";
        $result = $this->db->query($sql);

        $fe_users = array();

        while ($db_field = $result->fetch_assoc()) {
            $fe_users[intval($db_field['uid'])] = $db_field['ldap_entryuuid'];
        }

        $result->free();
        $result->close();

        $ldapUsers = $this->getLdapUsers($baseDn, $allLdapUsersFilter, array('cn', 'dn', 'entryuuid', 'phototimestamp'));

        // list all files in specified path
        $files = glob($jpegSearchPath);

        foreach($files as $path) {
            // get filename without path
            $index = strrpos($path, '/');
            $file = $index > 0 ? substr($path, $index + 1) : $path;
            $this->log_msg("Reading file $file\n", E_USER_NOTICE);

            // extract uid and file extension
            $index = strrpos($file, ".");
            $name = ltrim(substr($file, 0, $index), "0");
            $ext = substr($file, $index + 1);

            if ($ext !== "jpg")
                continue;

            // parse uid
            $uid = intval($name);
            if ($uid != $name)
                continue;

            $entryUuid = isset($fe_users[$uid]) ? $fe_users[$uid] : null;
            if ($entryUuid == null)
                continue;

            $ldapUser = isset($ldapUsers[$entryUuid]) ? $ldapUsers[$entryUuid] : null;
            if ($ldapUser == null)
                continue;

            // import photo to LDAP
            if ($this->setUserPhoto($ldapUser, $path)) {
                $count += 1;
            }
        }

        return $count;
    }

    /**
     * @param array $groups
     * @param array $ogCategories
     * @param string $dnActiveUsers
     * @param string $dnInactiveUsers
     * @param string $baseDn
     * @param bool $syncPasswords
     */
    public function syncUsers(array &$groups, array $ogCategories, $dnActiveUsers, $dnInactiveUsers, $baseDn, $syncPasswords=false) {

        /** @var mysqli_result $result */
        $result = $this->getUsers();

        while ($db_field = $result->fetch_assoc()) {
            // parse fields
            $user = new user($db_field);

            // get base dn
            if ($user->isInactive()) {
                $ou = $dnInactiveUsers;
                $required_ou_base = $dnInactiveUsers . ',' . $baseDn;
            } else {
                if ($user->hasOgCategory($ogCategories, 'Mitglied')) {
                    $ou = 'ou=mitglieder,' . $dnActiveUsers;
                } else {
                    // TODO: default is "extern" in future.
                    // $ou = 'ou=extern,' . $dnActiveUsers;
                    $ou = 'ou=mitglieder,' . $dnActiveUsers;
                }

                $required_ou_base = $dnActiveUsers . ',' . $baseDn;
            }

            $dn = "cn=$user->cn,$ou,$baseDn";

            $ldap_user = null;
            $ldap_user_dn = $this->getLdapUser($baseDn, $user->uid, $ldap_user, 'inetOrgPerson');

            if ($ldap_user === null) {
                $this->log_msg("Adding... $user->cn\n", E_NOTICE);

                $entry = array(
                    'objectclass' => array('top', 'person', 'organizationalPerson', 'inetOrgPerson', 'fegperson', 'simpleSecurityObject'),
                    'cn' => $user->cn,
                    'syncuserid' => $user->uid,
                    'syncusersource' => 'fe_users',
                    'userpassword' => $user->getSshaPassword()
                );

                $success = ldap_add($this->ldapConnection, $dn, $entry);
                if (!$success) {
                    $this->log_msg("Error adding $user->cn\n" . json_encode($entry, JSON_PRETTY_PRINT) . "\n", E_ERROR);
                    continue;
                } else {
                    $ldap_user_dn = $dn;
                    $ldap_user = $entry;
                }
            } else {
                if ($this->strEndsWith($ldap_user_dn, $required_ou_base) === false) {
                    $this->log_msg("Moving to correct OU... (old: $ldap_user_dn)\n", E_NOTICE);
                    list($new_rdn, $new_parent) = explode(',', $dn, 2);
                    ldap_rename($this->ldapConnection, $ldap_user_dn, $new_rdn, $new_parent, true);
                }
            }

            // Check if username was renamed.
            $old_cn = $ldap_user['cn'][0];
            if ($old_cn !== $user->cn) {
                list(,$new_parent) = explode(',', $ldap_user_dn, 2);
                ldap_rename($this->ldapConnection, $ldap_user_dn, 'cn=' . $user->cn, $new_parent, true);
            }

            if (!$user->isInactive()) {
                $user_groups = $user->getUserGroups();
                foreach ($user_groups as $g) {
                    if (isset($groups['all'][$g])) {
                        $groups['all'][$g]['members'][] = $dn;
                    }
                    if (isset($groups['fe_groups'][$g])) {
                        $groups['fe_groups'][$g]['members'][] = $dn;
                    }
                }

                $og_categories = $user->getOgCategories();
                foreach ($og_categories as $g) {
                    if (isset($groups['all'][$g])) {
                        $groups['all'][$g]['members'][] = $dn;
                    }
                    if (isset($groups['og_categories'][$g])) {
                        $groups['og_categories'][$g]['members'][] = $dn;
                    }
                }
            }

            $entry = $this->getLdapEntry($user, 'Germany', $syncPasswords);
            $this->cleanupLdapEntry($ldap_user, $entry);

            if (count($entry) > 0) {
                $this->log_msg("Updating $user->cn\n " . json_encode($entry, JSON_PRETTY_PRINT), E_USER_NOTICE);

                $success = ldap_modify($this->ldapConnection, $dn, $entry);

                if (!$success) {
                    $this->log_msg("Error updating $user->cn\n" . ldap_error($this->ldapConnection) . "\n" . json_encode($entry, JSON_PRETTY_PRINT) . "\n", E_ERROR);
                }
            }
        }

        $result->free();
    }

    /**
     * @param user $user
     * @param string $defaultCountry
     * @param bool $syncPasswords
     * @return array
     */
    private function getLdapEntry(user $user, $defaultCountry, $syncPasswords=false) {
        $entry = array(
            'displayname' => $user->data['name'],
            'typo3disabled' => $user->disable ? 'TRUE' : 'FALSE',

            'street' => $user->data['address'],
            'l' => $user->data['city'],
            'postalcode' => $user->data['zip'],

            'telephonenumber' => $this->sanitizeSingleLine($user->data['telephone']),
            'facsimiletelephonenumber' => $this->sanitizeSingleLine($user->data['fax']),

            'mail' => $user->data['email']
        );

        if ($syncPasswords) {
            $entry['userpassword'] = $user->getSshaPassword();
        }

        $dateOfBirth = intval($user->data['date_of_birth']);
        if ($dateOfBirth !== 0) {
            $entry['dateofbirth'] = date('Ymd', $dateOfBirth);
        } else if (isset($info[0]['dateofbirth'])) {
            $entry['dateofbirth'] = array();
        }

        if ($user->startTime !== 0) {
            $entry['startdate'] = $this->getLdapDate($user->startTime);
        } else if (isset($info[0]['startdate'])) {
            $entry['startdate'] = array();
        }

        if ($user->endTime !== 0) {
            $entry['enddate'] = $this->getLdapDate($user->endTime);
        } else if (isset($info[0]['enddate'])) {
            $entry['enddate'] = array();
        }

        $country = $user->data['country'];
        if (empty($country)) {
            $entry['c'] = $defaultCountry;
        } else {
            $entry['c'] = $country;
        }

        return $entry;
    }

    /**
     * @param array $groups
     * @param string $groupBaseDn
     */
    public function syncGroupData(array $groups, $groupBaseDn) {
        foreach ($groups as $group) {

            $dn = 'cn=' . $group['cn'] . ',' . $groupBaseDn;

            $new_members = is_array($group['members']) ? array_diff($group['members'], $group['origMembers']) : array();
            $removed_members = is_array($group['members']) ? array_diff($group['origMembers'], $group['members']) : $group['origMembers'];

            if (count($new_members) > 0) {

                $this->log_msg('New group members for ' . $group['cn'] . "\n" . json_encode($new_members, JSON_PRETTY_PRINT), E_USER_NOTICE);

                ldap_mod_add(
                    $this->ldapConnection,
                    $dn,
                    array('member' => array_values($new_members)));
            }

            if (count($removed_members) > 0) {

                $this->log_msg('Deleted group members for ' . $group['cn'] . "\n" . json_encode($removed_members, JSON_PRETTY_PRINT), E_USER_NOTICE);

                ldap_mod_del(
                    $this->ldapConnection,
                    $dn,
                    array('member' => array_values($removed_members)));
            }
        }
    }

    /**
     * @param array $old_ldap_user
     * @param array $entry
     */
    private function cleanupLdapEntry(array $old_ldap_user, array &$entry) {
        foreach ($entry as $k => $v) {
            if (empty($v)) {
                if (isset($old_ldap_user[$k])) {
                    $entry[$k] = array();
                } else {
                    unset($entry[$k]);
                }
            } else {
                $entry[$k] = utf8_encode($v);
                if (isset($old_ldap_user[$k]) && $old_ldap_user[$k][0] == $entry[$k]) {
                    unset($entry[$k]);
                }
            }
        }
    }

    /**
     * @param array $ldapGroup
     * @param string $new_name
     * @param string $dn
     * @return string
     */
    private function renameGroup(array $ldapGroup, $new_name, $dn) {
        $name_old = $ldapGroup['cn'];
        if (strcasecmp($name_old, $new_name) !== 0) {
            $this->log_msg("Renaming group $new_name... (old: $name_old)\n", E_NOTICE);
            ldap_rename($this->ldapConnection, "cn=$name_old,$dn", $new_name, $dn, true);

            return $new_name;
        }

        return $name_old;
    }

    /**
     * @param string $str
     * @return string
     */
    private function sanitizeSingleLine($str) {
        $parts = preg_split('/\r|\r\n|\n/', $str);
        return is_array($parts) && count($parts) > 0 ? $parts[0] : $str;
    }

    /**
     * @param $timestamp int php/unix timestamp
     * @return string Date/Time for LDAP field
     */
    private function getLdapDate($timestamp) {
        return date('YmdHis', $timestamp) . 'Z';
    }

    /**
     * @param $ldapDate string Date/Time in format "YmdHis".
     * @return int
     */
    private function getTimestamp($ldapDate) {
        $dateTime = DateTime::createFromFormat('YmdHis', $ldapDate);
        return $dateTime ? $dateTime->getTimestamp() : 0;
    }

    /**
     * @param string $target
     * @param string $test
     * @return bool
     */
    private function strEndsWith($target, $test) {
        $len_test = strlen($test);

        return (strlen($target) >= $len_test)
        && (strcasecmp(substr($target, -1 * $len_test), $test) === 0);
    }

    public function close() {
        $this->db->close();
        ldap_close($this->ldapConnection);
    }
}

class user {

    public $data;

    public $cn;

    public $uid;

    public $startTime;

    public $endTime;

    public $disable;

    private $ogCategories = null;

    function __construct(array $db_field) {
        $this->data = $db_field;

        $this->cn = utf8_encode(str_replace('  ', ' ', str_replace(',', '', $db_field['username'])));
        $this->uid = intval($db_field['uid']);

        $this->disable = intval($db_field['disable']) === 1;
        $this->startTime = intval($db_field['starttime']);
        $this->endTime = intval($db_field['endtime']);
    }

    public function getSshaPassword() {
        return $this->sshaEncode($this->data['password']);
    }

    public function isInactive(){
        return $this->disable
            || ($this->startTime !== 0 && $this->startTime > time())
            || ($this->endTime !== 0 && $this->endTime < time());
    }

    public function getUserGroups() {
        if (!isset($this->data['usergroup']))
            return array();

        return array_unique($this->splitIntegers($this->data['usergroup']));
    }

    public function getOgCategories() {
        if ($this->ogCategories === null) {
            if (!isset($this->data['og_categorys'])) {
                $this->ogCategories = array();
            } else {
                $this->ogCategories = array_unique($this->splitIntegers($this->data['og_categorys']));
            }
        }

        return $this->ogCategories;
    }

    public function hasOgCategory(array $ogCategories, $name) {
        $item = null;
        foreach ($ogCategories as $id => $category) {
            if ($category['name'] == $name) {
                $item = $category;
                break;
            }
        }

        return array_search($item['id'], $this->getOgCategories()) !== false;
    }

    /**
     * @param string $csv
     * @return array
     */
    private function splitIntegers($csv) {
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

    /**
     * @param string $text
     * @return string
     */
    private function sshaEncode($text) {
        $salt = '';
        for ($i=1; $i<=10; $i++) {
            $salt .= substr('0123456789abcdef', rand(0, 15), 1);
        }
        $hash = '{SSHA}' . base64_encode(pack('H*', sha1($text . $salt)) . $salt);
        return $hash;
    }
}