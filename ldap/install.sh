#!/bin/bash

UNINSTALL_SVN=0
# install svn if not installed previously
if [ "$(which svn)" = "" ]; then
    apt-get install subversion
    UNINSTALL_SVN=1
fi

# download ldap directory
svn export https://github.com/feg-giessen/ldap/trunk/ldap /etc/ldap

if [ "$UNINSTALL_SVN" = "1" ]; then
    apt-get remove subversion
fi

# install ldap
# keep old version of config files when asked!
apt-get install slapd ldap-utils