#!/bin/sh

SCRIPT=$(readlink -f "$0")
SCRIPTPATH=$(dirname "$SCRIPT")

ldapmodify -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/access_rights.ldif
