#!/bin/bash

service slapd stop
rm /var/lib/ldap/*.bdb
rm /var/lib/ldap/log*
rm /var/lib/ldap/__db*

slapadd -l $1 -F /etc/ldap/slapd.d -n 1
chown openldap:openldap -R /var/lib/ldap

service slapd start
