# Shared by setup.sh and update.sh. Layout on the server:
#   ~/domains/joviaplatform.ng/app          ← this git repo (website + backend/)
#   ~/domains/joviaplatform.ng/public_html  ← web root: index.php forwarding to Laravel, .htaccess, Filament assets

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"   # …/app/backend
REPO_DIR="$(dirname "$APP_DIR")"                              # …/app
WEB_ROOT="${WEB_ROOT:-$(dirname "$REPO_DIR")/public_html}"    # …/public_html
cd "$APP_DIR"

# Pick a PHP 8.2+ command-line binary (DirectAdmin and CloudLinux keep several versions)
PHP=""
for c in php /usr/local/php84/bin/php /usr/local/php83/bin/php /usr/local/php82/bin/php \
         /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php; do
    if command -v "$c" >/dev/null 2>&1 && "$c" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' 2>/dev/null; then
        PHP="$(command -v "$c")"; break
    fi
done
if [ -z "$PHP" ]; then
    echo "✘ No PHP 8.2+ found. In DirectAdmin → PHP Version Selector (or Domain Setup) choose PHP 8.2 or newer, then run this again." >&2
    exit 1
fi
missing=$("$PHP" -r 'echo implode(" ", array_filter(["intl","pdo_mysql","mbstring","fileinfo","openssl","curl"], fn($e) => !extension_loaded($e)));')
if [ -n "$missing" ]; then
    echo "✘ PHP ($PHP) is missing extensions: $missing — enable them in DirectAdmin → PHP Extensions (or ask HostAfrica support)." >&2
    exit 1
fi
echo "Using $("$PHP" -r 'echo PHP_VERSION;') at $PHP"

install_packages() {
    # Our own composer.phar, run with the PHP picked above (the host's `composer` may use an older PHP)
    local composer="$HOME/bin/composer.phar"
    if [ ! -f "$composer" ]; then
        mkdir -p "$HOME/bin"
        "$PHP" -r "copy('https://getcomposer.org/download/latest-stable/composer.phar', '$composer') || exit(1);"
    fi
    "$PHP" "$composer" install --no-dev --optimize-autoloader --no-interaction --no-progress
}

publish_web_root() {
    mkdir -p "$WEB_ROOT"
    # Keep whatever the host put there first (default index.html etc.)
    if [ ! -f "$WEB_ROOT/.jovia" ] && [ -n "$(ls -A "$WEB_ROOT" 2>/dev/null)" ]; then
        local backup="$HOME/public_html_backup_$(date +%Y%m%d%H%M%S)"
        mkdir -p "$backup" && mv "$WEB_ROOT"/* "$backup"/ 2>/dev/null || true
        echo "Moved the old web root files to $backup"
    fi
    cat > "$WEB_ROOT/index.php" <<EOF
<?php

// Jovia Network: the app lives in $REPO_DIR (outside the web root); /api, /admin and uploads go to Laravel.
require '$APP_DIR/public/index.php';
EOF
    cp "$APP_DIR/deploy/web-root.htaccess" "$WEB_ROOT/.htaccess"
    # Website files are served straight from the web root, so pages, CSS, images and fonts never start PHP
    for f in "$REPO_DIR"/*.html "$REPO_DIR"/robots.txt "$REPO_DIR"/sitemap.xml "$REPO_DIR"/site.webmanifest "$REPO_DIR"/favicon.svg "$REPO_DIR"/favicon.ico; do
        [ -f "$f" ] && cp "$f" "$WEB_ROOT/"
    done
    for dir in css js images fonts games blog; do
        rm -rf "$WEB_ROOT/$dir"
        cp -R "$REPO_DIR/$dir" "$WEB_ROOT/$dir"
    done
    # Filament's admin CSS/JS live next to the site's own css/ and js/
    for kind in css js; do
        rm -rf "$WEB_ROOT/$kind/filament"
        mkdir -p "$WEB_ROOT/$kind"
        cp -r "$APP_DIR/public/$kind/filament" "$WEB_ROOT/$kind/filament"
    done
    touch "$WEB_ROOT/.jovia"
    chmod -R u+rwX storage bootstrap/cache
}

optimize() {
    "$PHP" artisan optimize:clear >/dev/null
    "$PHP" artisan optimize
}
