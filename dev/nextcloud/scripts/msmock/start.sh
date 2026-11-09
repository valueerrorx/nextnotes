#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# Mock-Dienst (Profil msmock): Apache + mod_ssl aus dem NC-Image, TLS mit Test-Zertifikat (SAN login.microsoftonline.com, graph.microsoft.com), Steuerung auf Port 80.
set -eu
a2dissite 000-default >/dev/null 2>&1 || true
a2enmod ssl >/dev/null
cat > /etc/apache2/sites-enabled/msmock.conf <<'CONF'
<Directory /msmock/www>
	Require all granted
	AllowOverride None
	FallbackResource /index.php
</Directory>
<VirtualHost *:80>
	DocumentRoot /msmock/www
	AllowEncodedSlashes NoDecode
</VirtualHost>
<VirtualHost *:443>
	DocumentRoot /msmock/www
	AllowEncodedSlashes NoDecode
	SSLEngine on
	SSLCertificateFile /msmock/certs/server.crt
	SSLCertificateKeyFile /msmock/certs/server.key
</VirtualHost>
CONF
rm -f /tmp/msmock-state.json
exec apache2-foreground
