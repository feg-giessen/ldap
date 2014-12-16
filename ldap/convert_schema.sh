#!/bin/sh

mkdir /tmp/ldif_output

cd /etc/ldap/schema
slapcat -f schema_convert.conf -F /tmp/ldif_output -n0 
cd /tmp/ldif_output/cn\=config/cn\=schema/

head -n -7 cn\={0}core.ldif > ./core.ldif
head -n -7 cn\={1}collective.ldif > ./collective.ldif
head -n -7 cn\={2}corba.ldif > ./corba.ldif
head -n -7 cn\={3}cosine.ldif > ./cosine.ldif
head -n -7 cn\={4}duaconf.ldif > ./duaconf.ldif
head -n -7 cn\={5}dyngroup.ldif > ./dyngroup.ldif
head -n -7 cn\={6}inetorgperson.ldif > ./inetorgperson.ldif
head -n -7 cn\={7}misc.ldif > ./misc.ldif
head -n -7 cn\={8}nis.ldif > ./nis.ldif
head -n -7 cn\={9}openldap.ldif > ./openldap.ldif
head -n -7 cn\={10}ppolicy.ldif > ./ppolicy.ldif
head -n -7 cn\={11}ldapns.ldif > ./ldapns.ldif
head -n -7 cn\={12}fegperson.ldif > ./fegperson.ldif
head -n -7 cn\={13}feggroup.ldif > ./feggroup.ldif

rm cn\={*

sed -i 's/{[0-9]*}//g' *.ldif
sed -i 's/dn: cn=\([a-z]*\)/dn: cn=\1,cn=schema,cn=config/g' *.ldif

chmod a+r *.ldif

cp -f core.ldif /etc/ldap/schema/
cp -f collective.ldif /etc/ldap/schema/
cp -f corba.ldif /etc/ldap/schema/
cp -f cosine.ldif /etc/ldap/schema/
cp -f duaconf.ldif /etc/ldap/schema/
cp -f dyngroup.ldif /etc/ldap/schema/
cp -f inetorgperson.ldif /etc/ldap/schema/
cp -f misc.ldif /etc/ldap/schema/
cp -f nis.ldif /etc/ldap/schema/
cp -f openldap.ldif /etc/ldap/schema/
cp -f ppolicy.ldif /etc/ldap/schema/
cp -f ldapns.ldif /etc/ldap/schema/
cp -f fegperson.ldif /etc/ldap/schema/
cp -f feggroup.ldif /etc/ldap/schema/

cd /etc/ldap/schema
rm -r /tmp/ldif_output
