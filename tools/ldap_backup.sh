#!/bin/bash
# dump the ldap database

OUTFILE_CONFIG=/var/www/vhosts/feg-giessen.de/backups/ldapdb-`/bin/date +%d-%m-%y_%H-%M`_config.ldif
OUTFILE_DATA=/var/www/vhosts/feg-giessen.de/backups/ldapdb-`/bin/date +%d-%m-%y_%H-%M`_data.ldif

#echo "Starting slapcat..."
/usr/sbin/slapcat -n0 > $OUTFILE_CONFIG && /usr/sbin/slapcat -n1 >> $OUTFILE_DATA
gzip -9 $OUTFILE_CONFIG
gzip -9 $OUTFILE_DATA
find `dirname $OUTFILE_CONFIG` -name "*.ldif" -mtime +30 -exec rm -f {} \;
find `dirname $OUTFILE_CONFIG` -name "*.ldif.gz" -mtime +30 -exec rm -f {} \;
STATE=$?
exit $STATE
