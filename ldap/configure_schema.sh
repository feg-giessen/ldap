#!/bin/sh

SCRIPT=$(readlink -f "$0")
SCRIPTPATH=$(dirname "$SCRIPT")

echo "base schemas..."
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/collective.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/corba.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/cosine.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/duaconf.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/dyngroup.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/inetorgperson.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/misc.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/nis.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/openldap.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/ppolicy.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/ldapns.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/fegperson.ldif -c
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/feggroup.ldif -c

echo "db..."
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/db.ldif
ldapmodify -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/access_rights.ldif

echo "memberOf overlay..."
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/memberof_add.ldif
ldapadd -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/memberof_config.ldif

echo "sizelimit..."
ldapmodify -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/sizelimit.ldif

echo "certs.."
#ldapmodify -Y EXTERNAL -H ldapi:/// -f $SCRIPTPATH/schema/certinfo.ldif
