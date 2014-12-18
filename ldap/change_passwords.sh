#!/bin/bash

echo "Enter root password:"
ROOTPW=$(slappasswd)
echo "Root pw is $ROOTPW\n"

echo "\nEnter ldapsync password:"
LDAPSYNCPW=$(slappasswd)
echo "ldapsync pw is $LDAPSYNCPW\n"

echo "\nEnter owncloud password:"
OCPW=$(slappasswd)
echo "owncloud pw is $OCPW\n"

echo "\nEnter typo3 password:"
TYPOPW=$(slappasswd)
echo "typo3 pw is $TYPOPW\n"

cat << EOF > /tmp/pw.ldif
dn: olcDatabase={0}config,cn=config
changetype: modify
replace: olcRootPW
olcRootPW: $ROOTPW

dn: olcDatabase={1}hdb,cn=config
changetype: modify
replace: olcRootPW
olcRootPW: $ROOTPW

dn: cn=ldapsync,ou=services,dc=feg-giessen,dc=de
changetype: modify
replace: userPassword
userPassword: $LDAPSYNCPW

dn: cn=owncloud,ou=services,dc=feg-giessen,dc=de
changetype: modify
replace: userPassword
userPassword: $OCPW

dn: cn=typo3,ou=services,dc=feg-giessen,dc=de
changetype: modify
replace: userPassword
userPassword: $TYPOPW
EOF

ldapmodify -Y EXTERNAL -H ldapi:/// -f /tmp/pw.ldif
rm /tmp/pw.ldif
