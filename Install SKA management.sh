#!/usr/bin/env bash
# Setup server for centralized key management via SSH Key Authority
# Verifies login via a temporary key (loopback test)
set -Eeuo pipefail

# ---- Config
SYNC_USER="keys-sync"
BASE_DIR="/var/local/keys-sync"
STATUS_FILE="${BASE_DIR}.status"
main_cfg="/etc/ssh/sshd_config"
DROPIN_DIR="/etc/ssh/sshd_config.d"
DROPIN_FILE="00-apply-central-ssh-key-management.conf" #first one wins
DROPIN_ROOTLOGIN="00-only-key-auth-for-rootlogin.conf" #first one wins
# If you want to RETAIN ~/.ssh/authorized_keys in addition to central store, set to 1
KEEP_HOME_AUTH_KEYS="${KEEP_HOME_AUTH_KEYS:-0}"

SKA_PUBKEY='ssh-rsa AAAAB3N.....4Zb1P3IcElzpRyMQ== root@SKA' #replace with your own PUBLIC ssh key from 'config/keys-sync.pub'

# ---- Root check ----
if [[ $EUID -ne 0 ]]; then
  echo "Please run as root." >&2
  exit 1
fi

# ---- Create keys-sync user (system) if missing ----
if ! id -u "$SYNC_USER" >/dev/null 2>&1; then
  if command -v useradd >/dev/null 2>&1; then
    useradd --system --home-dir "$BASE_DIR" --shell /bin/sh "$SYNC_USER"
  else
    adduser --system --disabled-password --home "$BASE_DIR" --shell /bin/sh "$SYNC_USER"
  fi
fi

# Ensure home dir exists, ownership, and perms 0711
install -d -o "$SYNC_USER" -m 0711 "$BASE_DIR"

# ---- Bootstrap SKA access for 'keys-sync' ----
# File path must be /var/local/keys-sync/keys-sync because AuthorizedKeysFile uses %u
tmpkey="$(mktemp)"
printf '%s\n' "$SKA_PUBKEY" > "$tmpkey"
# Owned by keys-sync, perms 0644 per SKA instructions
install -o "$SYNC_USER" -m 0644 "$tmpkey" "${BASE_DIR}/keys-sync"
rm -f "$tmpkey"

# ---- Status file (owned by keys-sync, 0644) ----
touch "$STATUS_FILE"
chmod 0644 "$STATUS_FILE"
chown -R "$SYNC_USER" "$BASE_DIR"*

# ---- Ensure sshd uses drop-ins; add Include if missing ----
install -d -m 0755 "$DROPIN_DIR"
if ! grep -Eiq '^\s*Include\s+/etc/ssh/sshd_config\.d/\*\.conf' "$main_cfg"; then #TODO - HAS TO BE AT THE TOP!
#   printf '\nInclude %s/*.conf\n' "$DROPIN_DIR" >> "$main_cfg"
  echo "Include $DROPIN_DIR/*.conf\
$(cat $main_cfg)" > $main_cfg
#   sed -i 'Include /etc/ssh/sshd_config.d/*.conf' "$main_cfg"
fi

# ---- Write our drop-in ----
if [[ "$KEEP_HOME_AUTH_KEYS" = "1" ]]; then
  AUTH_LINE='AuthorizedKeysFile .ssh/authorized_keys .ssh/authorized_keys2 /var/local/keys-sync/%u'
else
  AUTH_LINE='AuthorizedKeysFile /var/local/keys-sync/%u'
fi

cat > "${DROPIN_DIR}/${DROPIN_FILE}" <<EOF
# Central SSH key management via SSH Key Authority
${AUTH_LINE}
StrictModes no
# PubkeyAcceptedAlgorithms +ssh-rsa
Match User keys-sync
    PasswordAuthentication no
EOF

cat > ${DROPIN_DIR}/${DROPIN_ROOTLOGIN} <<EOF
PermitRootLogin prohibit-password
Match User root
    PasswordAuthentication no
EOF

chmod 0644 "${DROPIN_DIR}/"*
chmod 0644 "$main_cfg"

# ---- Validate config before (re)load ----
SSHD_BIN="$(command -v sshd || echo /usr/sbin/sshd)"
"$SSHD_BIN" -t

# ---- Reload / restart sshd safely across distros ----
if command -v systemctl >/dev/null 2>&1; then
  systemctl try-reload-or-restart sshd.service 2>/dev/null || true
  systemctl try-reload-or-restart ssh.service 2>/dev/null || true
  systemctl try-reload-or-restart openssh-server.service 2>/dev/null || true
else
  if [[ -x /etc/init.d/sshd ]]; then /etc/init.d/sshd reload || /etc/init.d/sshd restart || true; fi
  if [[ -x /etc/init.d/ssh  ]]; then /etc/init.d/ssh  reload || /etc/init.d/ssh  restart  || true; fi
fi

# ---- SELinux handling #TODO - Always run the selinux commands? The logic needs to be better... basically just find out whether there's linux support or not
if command -v getenforce >/dev/null 2>&1 && [[ "$(getenforce)" == "Enforcing" ]]; then
  cat >&2 <<'EOF'
[SELinux] If key logins fail, you may need to label /var/local/keys-sync so sshd_t can read it:
  semanage fcontext -a -t ssh_home_t "/var/local/keys-sync(/.*)?"
  restorecon -Rv /var/local/keys-sync
EOF
fi
semanage fcontext -a -t ssh_home_t "/var/local/keys-sync(/.*)?" | true #nofail
restorecon -Rv /var/local/keys-sync | true #nofail

# ---- Verification: loopback login test for keys-sync (non-fatal) ----
verify_loopback() {
  # prerequisites
  if ! command -v ssh >/dev/null 2>&1; then
    echo "[-] Verification skipped: 'ssh' client not found." >&2
    return 0
  fi
  if ! command -v ssh-keygen >/dev/null 2>&1; then
    echo "[-] Verification skipped: 'ssh-keygen' not found." >&2
    return 0
  fi

  # Create a temporary keypair
  tmpkey="$(mktemp -u /tmp/ska-verify.XXXXXXXX)"
  if ! ssh-keygen -t ed25519 -N "" -f "$tmpkey" >/dev/null 2>&1; then
    if ! ssh-keygen -t rsa -b 3072 -N "" -f "$tmpkey" >/dev/null 2>&1; then
      echo "[-] Verification skipped: unable to create a temporary keypair." >&2
      return 0
    fi
  fi

  # Insert a clearly marked block into /var/local/keys-sync/keys-sync
  verify_file="${BASE_DIR}/keys-sync"
  marker_begin="# SKA-VERIFY BEGIN"
  marker_end="# SKA-VERIFY END"
  # Remove any stale block first
  if grep -q "^${marker_begin}\$" "$verify_file" 2>/dev/null; then
    sed -i "/^${marker_begin}\$/,/^${marker_end}\$/d" "$verify_file"
  fi
  {
    echo "$marker_begin"
    cat "${tmpkey}.pub"
    echo "$marker_end"
  } >> "$verify_file"

  # Ensure ownership/perms per SKA recipe
  chown "$SYNC_USER" "$verify_file"
  chmod 0644 "$verify_file"

  # Give sshd a moment to notice file change (usually instant, but harmless)
  sleep 0.2

  # Attempt loopback SSH as keys-sync using the temp private key
  SSH_OPTS=(
    -o BatchMode=yes
    -o StrictHostKeyChecking=no
    -o UserKnownHostsFile=/dev/null
    -o ConnectTimeout=5
    -o PreferredAuthentications=publickey
    -o PasswordAuthentication=no
    -i "$tmpkey"
  )

  set +e
  ssh "${SSH_OPTS[@]}" "${SYNC_USER}@localhost" true >/dev/null 2>&1
  rc=$?
  set -e

  # Clean up temp block and files
  sed -i "/^${marker_begin}\$/,/^${marker_end}\$/d" "$verify_file" || true
  rm -f "$tmpkey" "${tmpkey}.pub"

  if [[ $rc -eq 0 ]]; then
    echo "[+] Verification: SUCCESS — public-key login via ${BASE_DIR}/%u works for '${SYNC_USER}'."
  else
    echo "[!] Verification: FAILED (ssh exit=$rc)." >&2
    echo "    Tips:" >&2
    echo "     • Check sshd logs: 'journalctl -u sshd --since -5m' (or /var/log/auth.log or /var/log/secure)." >&2
    echo "     • If SELinux is Enforcing, apply the labeling hint shown above." >&2
    echo "     • Ensure firewall allows localhost:22 and sshd is listening (ss -ltnp | grep :22)." >&2
  fi
}

verify_loopback

echo "✓ SSH Key Authority setup complete. Central path: ${BASE_DIR}/%u"
