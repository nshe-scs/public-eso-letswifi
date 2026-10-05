# eso-letswifi: Let's Wi-Fi portal (geteduroam/letswifi-portal) + SimpleSAMLphp on Red Hat's PHP 8.3 UBI.
# Nothing site-specific or secret is baked in; config lives in ./vols and ./custom.env.
FROM registry.access.redhat.com/ubi9/php-83:latest

ARG LETSWIFI_VERSION=v2.0.0-beta7
ARG SSP_VERSION=2.4.10

USER 0

# mod_md is in RHEL 9 AppStream. On a subscribed RHEL host, podman passes the host's
# entitlement into builds, so this works even if the free UBI repos don't carry mod_md.
RUN dnf -y install mod_md \
 && dnf -y update \
 && dnf clean all \
 && php -m | grep -qix pdo_sqlite

# Drop the image's default 8443 vhost (self-signed "localhost" cert) so only ours answers,
# and keep php-fpm small: workers start on demand and exit when idle.
RUN sed -i '/^<VirtualHost _default_:8443>/,/^<\/VirtualHost>/d' /etc/httpd/conf.d/ssl.conf \
 && ! grep -q '_default_:8443' /etc/httpd/conf.d/ssl.conf \
 && sed -i -e 's/^pm = .*/pm = ondemand/' -e 's/^pm.max_children = .*/pm.max_children = 8/' /etc/php-fpm.d/www.conf \
 && grep -q '^pm = ondemand' /etc/php-fpm.d/www.conf

# The release tarball bundles letswifi's PHP dependencies (no composer/git needed).
# It omits the SQL schema, so fetch that from the same git tag.
WORKDIR /opt/app-root
RUN curl -fsSL "https://github.com/geteduroam/letswifi-portal/releases/download/${LETSWIFI_VERSION}/letswifi-portal-${LETSWIFI_VERSION}.tar.gz" | tar xz \
 && mv letswifi-portal letswifi \
 && curl -fsSL -o letswifi/letswifi.sqlite.sql "https://raw.githubusercontent.com/geteduroam/letswifi-portal/${LETSWIFI_VERSION}/sql/letswifi.sqlite.sql" \
 && curl -fsSL "https://github.com/simplesamlphp/simplesamlphp/releases/download/v${SSP_VERSION}/simplesamlphp-${SSP_VERSION}-full.tar.gz" | tar xz \
 && mv "simplesamlphp-${SSP_VERSION}" letswifi/simplesamlphp \
 && mkdir -p defaults/ssp-dist letswifi/var md \
 && cp letswifi/simplesamlphp/config/config.php.dist defaults/ssp-dist/ \
 && cd letswifi/config \
 && mv clients-eduroam.conf.php clients.conf.php \
 && rm -f clients-govroam.conf.php branding-govroam.conf.php ./*.dev.php database.conf.dist-sqlite.php realms/*example.com.conf.php \
 && cp -a /opt/app-root/letswifi/config /opt/app-root/defaults/letswifi-config

# Defaults copied into ./vols on first start (never overwritten afterwards)
COPY config/letswifi.conf.php config/database.conf.php /opt/app-root/defaults/letswifi-config/
COPY config/provider.conf.php /opt/app-root/defaults/provider.conf.php
COPY config/ssp/ /opt/app-root/defaults/ssp-config/
COPY httpd/letswifi.conf /opt/app-root/etc/conf.d/letswifi.conf
COPY discovery/ /opt/app-root/eso-letswifi/discovery/
COPY lib/ /opt/app-root/eso-letswifi/lib/
COPY start.sh /usr/local/bin/letswifi-start

RUN chmod 755 /usr/local/bin/letswifi-start \
 && chown -R 1001:0 /opt/app-root/letswifi /opt/app-root/defaults /opt/app-root/md /opt/app-root/eso-letswifi \
 && chmod -R g=u /opt/app-root/letswifi /opt/app-root/defaults /opt/app-root/md /opt/app-root/eso-letswifi

USER 1001
CMD ["/usr/local/bin/letswifi-start"]
