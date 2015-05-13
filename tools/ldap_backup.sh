#!/bin/sh
BACKUPDATE=ldap-$( date +%y%m%d-%H%M )
BACKUPPATH=/home/backups

slapcat -b "cn=config" -l $BACKUPPATH/config-$BACKUPDATE.ldif
slapcat -b "dc=feg-giessen,dc=de"-l $BACKUPPATH/feg-giessen-$BACKUPDATE.ldif

gzip -9 $BACKUPPATH/config-$BACKUPDATE.ldif
gzip -9 $BACKUPPATH/feg-giessen-$BACKUPDATE.ldif
