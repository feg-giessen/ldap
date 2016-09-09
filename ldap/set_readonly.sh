#!/bin/sh

SCRIPT=$(readlink -f "$0")
SCRIPTPATH=$(dirname "$SCRIPT")

ldapmodify -Q -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/readonly_db.ldif
