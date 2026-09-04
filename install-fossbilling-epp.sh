#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

VERSION='1.2.0'
ARCHIVE="fossbilling-epp-v${VERSION}.tar.gz"
DOWNLOAD_URL="https://github.com/getnamingo/fossbilling-epp-registrar/releases/download/v${VERSION}/${ARCHIVE}"
ARCHIVE_SHA256='fd0fe6fc1b5bfddcd4716d73d6fcf37887f1df106c15bb7e8833539d46fc773a'
CERT_DIR='/var/www'

CC_REGISTRIES=(
  registrebf switch niccl cocca cocca2 eurid afnic nicge carnet nicim switchli
  niclv nicmx sidn iisnu nask rotld iis hostmaster ye zadna
)
G_REGISTRIES=(
  central core dns godaddy google hello identity org itcom namingo regtons ryce
  tucows verisign zdns
)
R_REGISTRIES=(drsua ukrnames)

usage() {
  cat <<EOF_USAGE
Usage:
  $(basename "$0") <registry> [fossbilling_path]

Examples:
  $(basename "$0") namingo
  $(basename "$0") namingo /var/www/fossbilling

Supported registry profiles:
  cc: $(printf '%s ' "${CC_REGISTRIES[@]}")
  g:  $(printf '%s ' "${G_REGISTRIES[@]}")
  r:  $(printf '%s ' "${R_REGISTRIES[@]}")
EOF_USAGE
}

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

info() {
  printf '%s\n' "$*"
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

is_supported_registry() {
  local wanted=$1 item
  for item in "${CC_REGISTRIES[@]}" "${G_REGISTRIES[@]}" "${R_REGISTRIES[@]}"; do
    [[ "$item" == "$wanted" ]] && return 0
  done
  return 1
}

is_fossbilling_root() {
  local path=${1%/}
  [[ -f "$path/di.php" && -d "$path/library/Registrar/Adapter" ]]
}

find_fossbilling_root() {
  local found=() di root

  while IFS= read -r di; do
    root=${di%/di.php}
    if is_fossbilling_root "$root"; then
      found+=("$root")
    fi
  done < <(find /var/www -maxdepth 5 -type f -name di.php -print 2>/dev/null || true)

  if ((${#found[@]} == 1)); then
    printf '%s\n' "${found[0]}"
    return 0
  fi

  return 1
}

prompt_fossbilling_root() {
  local path
  while true; do
    if [[ -r /dev/tty ]]; then
      read -r -p 'FOSSBilling path: ' path </dev/tty
    else
      die 'FOSSBilling was not detected under /var/www and no interactive terminal is available.'
    fi

    path=${path%/}
    if is_fossbilling_root "$path"; then
      printf '%s\n' "$path"
      return 0
    fi

    printf 'Not a valid FOSSBilling root: %s (expected di.php and library/Registrar/Adapter)\n' "$path" >&2
  done
}

ask_yes_no() {
  local prompt=$1 answer

  if [[ ! -r /dev/tty ]]; then
    return 1
  fi

  while true; do
    read -r -p "$prompt [y/N]: " answer </dev/tty
    case "${answer,,}" in
      y|yes) return 0 ;;
      ''|n|no) return 1 ;;
      *) printf 'Please answer yes or no.\n' >&2 ;;
    esac
  done
}

if (($# == 0)); then
  usage
  exit 0
fi

if [[ "${1:-}" == '-h' || "${1:-}" == '--help' ]]; then
  usage
  exit 0
fi

(($# <= 2)) || die 'Too many arguments.'

registry=${1,,}
is_supported_registry "$registry" || {
  usage >&2
  die "Unsupported registry profile: $registry"
}

# Equivalent to PHP ucfirst() because registry has already been normalized to lowercase.
registry_class="${registry^}"

need_cmd curl
need_cmd tar
need_cmd sha256sum
need_cmd sed
need_cmd grep
need_cmd find
need_cmd crontab
need_cmd php

if [[ ${EUID} -eq 0 ]]; then
  SUDO=()
else
  need_cmd sudo
  SUDO=(sudo)
fi

if (($# == 2)); then
  foss_path=${2%/}
  is_fossbilling_root "$foss_path" || die "Invalid FOSSBilling path: $foss_path"
else
  if foss_path=$(find_fossbilling_root); then
    info "Detected FOSSBilling: $foss_path"
  else
    info 'FOSSBilling was not uniquely detected under /var/www.'
    foss_path=$(prompt_fossbilling_root)
  fi
fi

adapter_dir="$foss_path/library/Registrar/Adapter"
adapter_dest="$adapter_dir/${registry_class}.php"
sync_dest="$foss_path/${registry_class}Sync.php"

workdir=$(mktemp -d -t namingo-foss-epp.XXXXXXXX)
cleanup() {
  rm -rf -- "$workdir"
}
trap cleanup EXIT INT TERM

archive_path="$workdir/$ARCHIVE"

info "Downloading Namingo FOSSBilling EPP module v${VERSION}..."
curl --fail --location --silent --show-error \
  --retry 3 --retry-delay 1 --retry-all-errors \
  --proto '=https' --tlsv1.2 \
  --output "$archive_path" "$DOWNLOAD_URL"

printf '%s  %s\n' "$ARCHIVE_SHA256" "$archive_path" | sha256sum --check --status \
  || die 'Downloaded archive failed SHA-256 verification.'

# Refuse suspicious archive paths even though the archive is checksum-pinned.
if tar -tzf "$archive_path" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
  die 'Archive contains an unsafe path.'
fi

tar -xzf "$archive_path" -C "$workdir"
module_dir="$workdir/fossbilling-epp-v${VERSION}"

[[ -d "$module_dir" ]] || die "Unexpected archive structure: missing $(basename "$module_dir")"
[[ -f "$module_dir/epp.php" ]] || die 'Unexpected archive structure: missing epp.php'
[[ -f "$module_dir/eppSync.php" ]] || die 'Unexpected archive structure: missing eppSync.php'
[[ -d "$module_dir/namingo" ]] || die 'Unexpected archive structure: missing namingo/'

adapter_tmp="$workdir/${registry_class}.php"
sync_tmp="$workdir/${registry_class}Sync.php"
cp -- "$module_dir/epp.php" "$adapter_tmp"
cp -- "$module_dir/eppSync.php" "$sync_tmp"

sed -i "s/Registrar_Adapter_EPP/Registrar_Adapter_${registry_class}/g" "$adapter_tmp"
sed -i "s/\\\$registrar = \"Epp\";/\\\$registrar = \"${registry_class}\";/" "$sync_tmp"

grep -Fq "Registrar_Adapter_${registry_class}" "$adapter_tmp" \
  || die 'Failed to customize registrar adapter class.'
grep -Fq "\$registrar = \"${registry_class}\";" "$sync_tmp" \
  || die 'Failed to customize sync registrar name.'

# The Composer dependency tree is shared by all generated EPP modules.
if [[ -e "$foss_path/namingo" ]]; then
  [[ -d "$foss_path/namingo" ]] || die "$foss_path/namingo exists but is not a directory."
  info "Shared namingo/ directory already exists, skipping copy."
else
  "${SUDO[@]}" mv -- "$module_dir/namingo" "$foss_path/namingo"
  info "Installed shared namingo/ directory."
fi

# Install/upgrade only the files owned by this generated module.
"${SUDO[@]}" install -o www-data -g www-data -m 0644 -- "$adapter_tmp" "$adapter_dest"
"${SUDO[@]}" install -o www-data -g www-data -m 0644 -- "$sync_tmp" "$sync_dest"
"${SUDO[@]}" chown -R www-data:www-data -- "$foss_path/namingo"

php_bin=$(command -v php)
cron_line="0 0,12 * * * ${php_bin} ${sync_dest}"
cron_tmp="$workdir/crontab"

(crontab -l 2>/dev/null || true) > "$cron_tmp"
if grep -Fq -- "$sync_dest" "$cron_tmp"; then
  info "Cron entry for ${registry_class}Sync.php already exists, skipping."
else
  printf '%s\n' "$cron_line" >> "$cron_tmp"
  crontab "$cron_tmp"
  info "Added cron job for user $(id -un): $cron_line"
fi

cert_path="$CERT_DIR/${registry}_cert.pem"
key_path="$CERT_DIR/${registry}_key.pem"
cert_generated='no'

if ask_yes_no "Generate a self-signed TEST EPP certificate for ${registry_class}?"; then
  need_cmd openssl

  if [[ -e "$cert_path" || -e "$key_path" ]]; then
    die "Refusing to overwrite an existing test certificate/key: $cert_path or $key_path"
  fi

  cert_tmp="$workdir/${registry}_cert.pem"
  key_tmp="$workdir/${registry}_key.pem"

  openssl genrsa -out "$key_tmp" 2048 >/dev/null 2>&1
  openssl req -new -x509 \
    -key "$key_tmp" \
    -out "$cert_tmp" \
    -days 365 \
    -sha256 \
    -subj "/CN=${registry_class} EPP Test/O=Namingo Test/OU=EPP" \
    >/dev/null 2>&1

  "${SUDO[@]}" install -o www-data -g www-data -m 0600 -- "$cert_tmp" "$cert_path"
  "${SUDO[@]}" install -o www-data -g www-data -m 0600 -- "$key_tmp" "$key_path"
  cert_generated='yes'
fi

cat <<EOF_DONE

Installation complete.
Registry module: ${registry_class}
FOSSBilling path: ${foss_path}
Registrar adapter: ${adapter_dest}
Sync script: ${sync_dest}
Cron: ${cron_line}
EOF_DONE

if [[ "$cert_generated" == 'yes' ]]; then
  cat <<EOF_CERT
Test certificate: ${cert_path}
Test private key: ${key_path}

These are self-signed TEST credentials only. Replace them with the certificate/key accepted by the registry before production EPP use.
EOF_CERT
else
  info 'Test certificate: not generated.'
fi
