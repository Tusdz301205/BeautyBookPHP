#!/bin/sh
# Prepares the acceptance preview inside the preview_app container: schema, demo accounts, a throwaway FCM service
# account for the trial stand-in, and a few finance fixtures. Safe to run again (it rebuilds the preview database only).
set -eu
case "${DB_DATABASE:-}" in beautybook_preview) ;; *) echo "Refusing to run: DB_DATABASE is '${DB_DATABASE:-}', expected beautybook_preview" >&2; exit 2;; esac
php artisan migrate:fresh --seed --force
mkdir -p /var/preview
if [ ! -s "$FCM_SERVICE_ACCOUNT_FILE" ]; then
  php -r '$k = openssl_pkey_new(["private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA]); openssl_pkey_export($k, $pem); file_put_contents(getenv("FCM_SERVICE_ACCOUNT_FILE"), json_encode(["client_email" => "preview@beautybook-preview.iam.gserviceaccount.com", "private_key" => $pem]));'
  chmod 600 "$FCM_SERVICE_ACCOUNT_FILE"
fi
php docker/preview/fixture.php
