ldap
====

LDAP schema and tools for member directory.


## Content ##

- `ldap` - LDAP configuration
- `tools` - tools for synchronization

## LDAP installation and configuration ##

### Download configuration files and install ldap

Download `ldap/install.sh` and execute it on the target server as root

    sudo sh install.sh

*OR*

Download the `ldap` folder to /etc/ldap on your server and install *openldap* using `sudo apt-get install slapd ldap-utils`.

**Note:** Keep old version of config files when asked!
    
### Install ssl certificate

- Copy certificate to `/etc/ldap/ssl/[filename].crt`
- Convert the private key to *PKCS#1*: `openssl rsa -in mykey.old -out [filename].key`
- Copy private key to `/etc/ldap/ssl/[filename].key`
- Update `/etc/ldap/schema/certinfo.ldif` with path to certificate (`olcTLSCertificateFile`) and path to private key (`olcTLSCertificateKeyFile`).
- Apply ownership: `sudo chown openldap:openldap /etc/ldap/ssl/*`
- Apply permissions: `sudo chmod 0644 /etc/ldap/ssl/*.crt && sudo chmod 0400 /etc/ldap/ssl/*.key`

### Apply configuration to ldap
    
    # configure ldap
    # note: if on Ubuntu >= 12 -> replace /etc/ldap/schema/db.ldif with db_ubuntu12.ldif
    sudo sh /etc/ldap/configure_schema.sh

    # import basic schema
    sudo sh /etc/ldap/import_data.sh

    # apply access rights
    sudo sh /etc/ldap/configure_access.sh

### Edit port bindings

Edit the file `/etc/default/slapd`

(`sudo vim /etc/default/slapd`)

Set `SLAPD_SERVICES`:

    SLAPD_SERVICES="ldap://127.0.0.1:389/ ldaps:/// ldapi:///"

Restart the LDAP service:

    service slapd restart

### Edit DB_CONFIG

Edit the file `/var/lib/ldap/DB_CONFIG`

Insert the following line at the end:

    set_flags DB_LOG_AUTOREMOVE 

### Change LDAP passwords

Change all passwords preset in this configuration using `sudo sh /etc/ldap/change_passwords.sh`
