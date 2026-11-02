#!/bin/sh
# Offline-sandbox workaround (environment only, NOT a PrestaShop core change):
# The PS installer always downloads the "en" translation pack from i18n.prestashop-project.org.
# In network-restricted environments that host is unreachable, so we serve a minimal valid pack
# locally over TLS (host is pointed to 127.0.0.1 via docker-compose extra_hosts) and trust
# a throwaway local CA in PrestaShop's own cached CA bundle.
# In a normal hosting environment this script is unnecessary and can be removed.
set -e
D=/opt/i18n; mkdir -p $D && cd $D
V=$(php -r 'require "/var/www/html/src/Core/Version.php"; echo PrestaShop\PrestaShop\Core\Version::VERSION;')
openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=bxmp-local-ca" -keyout ca.key -out ca.crt 2>/dev/null
openssl req -newkey rsa:2048 -nodes -subj "/CN=i18n.prestashop-project.org" -keyout srv.key -out srv.csr 2>/dev/null
printf "subjectAltName=DNS:i18n.prestashop-project.org" > ext.cnf
openssl x509 -req -in srv.csr -CA ca.crt -CAkey ca.key -CAcreateserial -days 30 -extfile ext.cnf -out srv.crt 2>/dev/null
mkdir -p www/translations/$V/en-US www/mails/$V/en-US pack/en-US
echo '<?xml version="1.0" encoding="UTF-8"?><xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2"><file original="x" source-language="en" target-language="en" datatype="plaintext"><body></body></file></xliff>' > pack/en-US/ShopNotificationsSuccess.en-US.xlf
(cd pack && php -r '$z=new ZipArchive;$z->open($argv[1],ZipArchive::CREATE);$z->addFile("en-US/ShopNotificationsSuccess.en-US.xlf","ShopNotificationsSuccess.en-US.xlf");$z->close();' ../www/translations/$V/en-US/en-US.zip)
cp www/translations/$V/en-US/en-US.zip www/mails/$V/en-US/en-US.zip
for env in prod dev; do
  mkdir -p /var/www/html/var/cache/$env
  cat /var/www/html/vendor/composer/ca-bundle/res/cacert.pem ca.crt > /var/www/html/var/cache/$env/cacert.pem
done
chown -R www-data:www-data /var/www/html/var
cd www && nohup openssl s_server -quiet -accept 443 -cert ../srv.crt -key ../srv.key -WWW >/opt/i18n/server.log 2>&1 &
sleep 1
echo "* Local i18n pack server started for PrestaShop $V"
