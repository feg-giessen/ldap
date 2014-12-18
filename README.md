ldap
====

LDAP schema and tools for member directory.


## Content ##

- `ldap` - LDAP configuration
- `tools` - tools for synchronization

## LDAP installation and configuration ##

### Download configuration files and install ldap

Execute `install.sh` as root

    sudo sh install.sh
    
### Install ssl certificate

- Copy certificate to `/etc/ldap/ssl/[filename].crt`
- Copy private key to `/etc/ldap/ssl/[filename].key`
- Update `/etc/ldap/schema/certinfo.ldif` with path to certificate (`olcTLSCertificateFile`) and path to private key (`olcTLSCertificateKeyFile`).
- Apply ownership: `sudo chown openldap:openldap /etc/ldap/ssl/*`
- Apply permissions: `sudo chmod 0644 /etc/ldap/ssl/*.crt && sudo chmod 0400 /etc/ldap/ssl/*.key`

### Apply configuration to ldap
    
    # configure ldap
    # note: if on Ubuntu >= 12 -> replace /etc/ldap/schema/db.ldif with db_ubuntu12.ldif
    sh /etc/ldap/configure_schema.sh

    # import basic schema
    sh /etc/ldap/import_data.sh

### Change LDAP passwords

Change all passwords preset in this configuration using `sudo sh /etc/ldap/change_passwords.sh`
