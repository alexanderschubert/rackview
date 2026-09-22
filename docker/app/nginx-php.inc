# Weiterreichen an PHP-FPM.
#
# Die Oberflaeche liegt unter /srv/www, Laravel unter
# /var/www/html/public. Fuer die PHP-Anfragen wird deshalb ein
# eigener Wurzelpfad gesetzt.
#
# Reihenfolge beachten: erst fastcgi_params einbinden, dann die
# eigenen Werte. Nginx haengt gleichnamige Parameter hinten an,
# und PHP behaelt den zuletzt uebergebenen.
root /var/www/html/public;

include fastcgi_params;

fastcgi_pass 127.0.0.1:9000;
fastcgi_param SCRIPT_FILENAME /var/www/html/public/index.php;
fastcgi_param SCRIPT_NAME     /index.php;
fastcgi_param DOCUMENT_ROOT   /var/www/html/public;
fastcgi_read_timeout 60s;
fastcgi_hide_header X-Powered-By;
