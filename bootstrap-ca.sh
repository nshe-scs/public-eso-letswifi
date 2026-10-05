#!/bin/sh
# One-time, per institution: create the private root CA that letswifi uses to sign that
# institution's client certificates. Run on the host, outside the container.
set -e

serial=$(tr -dc 'A-F0-9' </dev/urandom | head -c8)
certfile="letswifi_ca_$serial.pem"
keyfile="letswifi_ca_$serial.key"

# ECDSA P-256
openssl ecparam -genkey -name prime256v1 -noout -out "$keyfile"

# Subject and extensions on the command line, so no OpenSSL config file is needed
openssl req -new -x509 -key "$keyfile" -sha256 -days 7300 -out "$certfile" \
	-subj "/CN=eduroam Support Organization LW Root ($serial)" \
	-addext "basicConstraints=critical,CA:TRUE" \
	-addext "keyUsage=critical,keyCertSign,cRLSign" \
	-addext "subjectKeyIdentifier=hash" \
	-addext "authorityKeyIdentifier=keyid:always"

cat <<MSG
Generated $certfile and $keyfile for: CN=eduroam Support Organization LW Root ($serial)

Store both securely offline. letswifi needs the key to issue certificates. Once the
realm exists, get the value for the institution's FreeRADIUS container
(FR_LETSWIFI_CA_CERT_BASE64) with: realm-ca-cert.py <realm>

Import into the running container with:

  cat $certfile $keyfile | podman exec -i eso-letswifi php /opt/app-root/letswifi/bin/letswifi ca import

Signer name for 'letswifi realm create':  --signer "CN=eduroam Support Organization LW Root ($serial)"
MSG
