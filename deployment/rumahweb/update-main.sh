#!/usr/bin/env bash

set -Eeuo pipefail

script_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
application_root="${APPLICATION_ROOT:-$(cd "$script_root/../.." && pwd -P)}"
hosting_home="$(cd -- "${CPANEL_HOME:-${HOME:?HOME tidak tersedia}}" && pwd -P)"
public_root_input="${PUBLIC_ROOT:-$hosting_home/public_html/schoolsync}"
site_url="${SITE_URL:-}"
backup_root="${BACKUP_ROOT:-$application_root/storage/app/deployment-backups}"
timestamp="$(date +%Y%m%d-%H%M%S)"
backup_dir="$backup_root/$timestamp"
front_template="$script_root/public-index.php"

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

validate_build() {
    BUILD_ROOT="$1" php -r '$root=getenv("BUILD_ROOT");$manifestFile=$root."/manifest.json";if(!is_file($manifestFile)){fwrite(STDERR,"Manifest Vite tidak ditemukan: ".$manifestFile.PHP_EOL);exit(1);}$manifest=json_decode(file_get_contents($manifestFile),true,512,JSON_THROW_ON_ERROR);$assets=[];foreach($manifest as$entry){if(isset($entry["file"]))$assets[]=$entry["file"];foreach($entry["css"]??[]as$css)$assets[]=$css;}foreach(array_unique($assets)as$asset){if(!is_file($root."/".$asset)){fwrite(STDERR,"Aset build tidak ditemukan: ".$asset.PHP_EOL);exit(1);}}'
}

set_env_value() {
    ENV_FILE="$application_root/.env" ENV_KEY="$1" ENV_VALUE="$2" php -r '$file=getenv("ENV_FILE");$key=getenv("ENV_KEY");$value=getenv("ENV_VALUE");$lines=file($file,FILE_IGNORE_NEW_LINES);if($lines===false){fwrite(STDERR,"Tidak dapat membaca .env".PHP_EOL);exit(1);}$found=false;foreach($lines as&$line){if(preg_match("/^\\s*".preg_quote($key,"/")."\\s*=/",$line)){if(!$found){$line=$key."=".$value;$found=true;}else{$line="# Duplikat ".$key." dihapus updater";}}}unset($line);if(!$found)$lines[]=$key."=".$value;if(file_put_contents($file,implode(PHP_EOL,$lines).PHP_EOL)===false){fwrite(STDERR,"Tidak dapat menulis .env".PHP_EOL);exit(1);}'
}

[[ -d "$application_root/.git" ]] || fail "Repository Git tidak ditemukan di $application_root."
[[ -f "$application_root/artisan" ]] || fail "Laravel tidak ditemukan di $application_root."
[[ -f "$application_root/.env" ]] || fail "File .env production belum tersedia di $application_root. Salin .env.example lalu isi koneksi MySQL."
[[ -f "$front_template" ]] || fail "Template index.php Rumahweb tidak ditemukan."

mkdir -p "$public_root_input"
public_root="$(cd "$public_root_input" && pwd -P)"

case "$public_root" in
    "$hosting_home/public_html"|"$hosting_home/public_html"/*) ;;
    *) fail "PUBLIC_ROOT harus berada di dalam $hosting_home/public_html." ;;
esac

[[ "$public_root" != "/" ]] || fail "PUBLIC_ROOT tidak boleh menunjuk ke root filesystem."
[[ "$public_root" != "$application_root" ]] || fail "PUBLIC_ROOT tidak boleh sama dengan application root."

cd "$application_root"

current_branch="$(git branch --show-current)"
[[ "$current_branch" == "main" ]] || fail "Updater harus dijalankan pada branch main. Branch saat ini: $current_branch."

if ! git diff --quiet || ! git diff --cached --quiet; then
    fail "Ada perubahan pada file yang dilacak Git. Commit atau pulihkan perubahan tersebut terlebih dahulu."
fi

if [[ -n "$(git ls-files --others --exclude-standard)" ]]; then
    fail "Ada file Git yang belum dilacak. Commit, pindahkan, atau abaikan file tersebut terlebih dahulu."
fi

printf '%s\n' 'Mengambil pembaruan branch main dari GitHub...'
printf 'Application root: %s\nDocument root:    %s\n' "$application_root" "$public_root"
[[ -n "$site_url" ]] && printf 'URL:              %s\n' "$site_url"
git pull --ff-only origin main

[[ -f public/build/manifest.json ]] || fail "public/build/manifest.json tidak ada. Jalankan build di lokal lalu commit folder public/build."
printf '%s\n' 'Memvalidasi aset Vite...'
validate_build "$application_root/public/build"

mkdir -p "$backup_dir"
cp -a "$application_root/.env" "$backup_dir/.env.before-update"
chmod 600 "$backup_dir/.env.before-update"

if [[ -n "$site_url" ]]; then
    printf '%s\n' 'Menyesuaikan APP_URL production...'
    set_env_value APP_URL "${site_url%/}"
fi

if [[ -x "$hosting_home/bin/composer" ]]; then
    composer_command=("$hosting_home/bin/composer")
elif command -v composer >/dev/null 2>&1; then
    composer_command=(composer)
else
    fail "Composer tidak ditemukan."
fi

printf '%s\n' 'Memasang dependency PHP production...'
"${composer_command[@]}" install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --no-scripts

if ! grep -Eq '^APP_KEY=.+' .env; then
    printf '%s\n' 'Membuat APP_KEY untuk instalasi pertama...'
    php artisan key:generate --force
fi

printf '%s\n' 'Menyiapkan Laravel dan database MySQL...'
php artisan config:clear
php artisan package:discover
php artisan migrate --force
php artisan db:seed --class=DatabaseSeeder --force
php artisan optimize:clear

printf 'Membuat backup file publik di %s...\n' "$backup_dir"
for target in index.php .htaccess .user.ini build favicon.ico robots.txt; do
    if [[ -e "$public_root/$target" ]]; then
        cp -a "$public_root/$target" "$backup_dir/"
    fi
done

build_staging="$public_root/.schoolsync-build-$timestamp"
mkdir -p "$build_staging"
cp -a "$application_root/public/build/." "$build_staging/"
validate_build "$build_staging"

if [[ -e "$public_root/build" ]]; then
    mv "$public_root/build" "$backup_dir/build-live"
fi
mv "$build_staging" "$public_root/build"

printf '%s\n' 'Menyalin file publik tanpa menghapus upload atau file hosting lain...'
shopt -s dotglob nullglob
for source in "$application_root/public/"*; do
    name="${source##*/}"
    case "$name" in
        build|index.php|storage) continue ;;
    esac
    cp -a "$source" "$public_root/"
done
shopt -u dotglob nullglob

APP_ROOT="$application_root" FRONT_TEMPLATE="$front_template" FRONT_OUTPUT="$public_root/index.php.new" php -r '$root=var_export(getenv("APP_ROOT"),true);$template=file_get_contents(getenv("FRONT_TEMPLATE"));if($template===false){fwrite(STDERR,"Template index.php tidak dapat dibaca.".PHP_EOL);exit(1);}if(file_put_contents(getenv("FRONT_OUTPUT"),str_replace("'__SCHOOLSYNC_APP_ROOT__'",$root,$template))===false){fwrite(STDERR,"index.php production tidak dapat ditulis.".PHP_EOL);exit(1);}'
mv "$public_root/index.php.new" "$public_root/index.php"
php -l "$public_root/index.php"

php artisan config:cache
php artisan route:cache
php artisan view:cache

validate_build "$public_root/build"

printf '\nDeployment SchoolSync selesai.\n'
[[ -n "$site_url" ]] && printf 'Aplikasi: %s\n' "${site_url%/}"
printf 'Public:   %s\n' "$public_root"
printf 'Backup:   %s\n' "$backup_dir"
printf '%s\n' 'Periksa login admin, status komputer, installer, satu perintah browser, dan satu unduhan file.'
