#!/bin/sh

SCRIPT=$(readlink -f "$0")
SCRIPTPATH=$(dirname "$SCRIPT")

ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/feg-schema/base.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/feg-schema/ou.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/feg-schema/service-accounts.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/feg-schema/gruppen.ldif -c
