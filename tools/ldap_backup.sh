#!/bin/bash
# dump the ldap database

OUTFILE=ldapdb-`/bin/date +%d-%m-%y_%H-%M`.ldif

#echo "Starting slapcat..."
/usr/sbin/slapcat -n0 > $OUTFILE && /usr/sbin/slapcat -n1 >> $OUTFILE
gzip -9 $OUTFILE
find `dirname $OUTFILE` -name "*.ldif" -mtime +30 -exec rm -f {} \;
find `dirname $OUTFILE` -name "*.ldif.gz" -mtime +30 -exec rm -f {} \;
STATE=$?
exit $STATE
