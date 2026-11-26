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

SKA_PROD_PUBKEY='ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAACAQCbqm5M2/Bs0P5oL9OVj80nvrBFyLhE4EZC1jYjlzdsegTTzf4IJ+WAWoj45IWDrgbh/WRV22mps4mh3CHN4PXIgGYit25Tod7+DS/E54m7O8ouSEeuKMdeyaQg1M14ohDodXL1CqYc3d10PW3mTWynej+8dn8y21DLljRSYrJVkQJSCTgjva3YC0FavTo+xF9s4Zik3+hU071HCLlNr07/1WyfPJcqFJDfl5q7MJMZF0bK/tTEhQrSP2ZQZxY9knNBkNVunlGzHDbq7tmyuNxwZnX/ndeiTK9W4RrtNW6YJjO8spTKndVcz42YiX6BgEqvapBC/CcxHt/dUF090Xz5oK8zx6cxVCqQicXLnnUkA69UuHRFQdu6avEjQsdh0fPafO5gCm4d/ZBnMBP3l8ddMd3XPOc0vUAsQwk9Lf2ctAAhWfBD36ngH/HB6XdrdgEk06TgJrlJJeFWeFqsRRAIjxBER4olyGdnS/nBhpQU9qPXwE/DU63DdBCUKvsviO/DQ4cdtHG4tTezBGy/5mc0UTi5V0TEg7P7D1Xvz0OZgXLL3zbiisK0HEVcXP4+J5IMHSD0Xk6Sp5QgLlA+SDZs0LTuYmrazabtF0KdKC1HJWFy64Y8nn9jWuzQwpkaNwC1bcoW15zvq9qWWZ9qK2zZZ/TjcQmX5hBME3IRM8K//Q== root@ska' #replace with your own PUBLIC ssh key from 'config/keys-sync.pub'
SKA_DEV_PUBKEY='ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAACAQCIMhzpg9VpQwCZdUXVIJaKrKn3wcDLNPmL7yryFElzXkkb8MvkAPW6bv957y7Cy+xhv4fXee3T2tEqK8gmdlIWpRKZP6xTU08T4Iwj3VQLKxZgN27jvRvKOSnFjXsUcgPCihupsD6xysoTkY9h0VYtFGMT5NpPSPhtvPoQEE2Agb8u/Icpb+Xv1Ve+PtyKey2+3oaTUdMS3fkFk64DyvDlpBQTaEChhw+8G4Iz50ZpltS2W3FwtCblLa/Kx/fWE/KSFrgVmExJ6tyz/+C1q/CfMBlVvfS4X+beo4qw3Ya6w4nvFGyafKmvo6XXSE5ORAJaGcLSueyUpqaGTOjoRrV8bRRd9IFTB5N8VDYbAsFcqzyMMcXCx+1v2NLG13LAPhXnI0XzOqD0yaCl6hbfXVMHBABaRD9xHQU3wnqkmhgYlV9f7YrDlSeSb5BN/QTVPJHzXcj7nzHwSsDr9XDOLK/6VSMB/ub6VsNAR0qWTH7RWzIXubXH15XTY89SsQOGfJk5B83x2Iyy1qFnPPIqSB6jgZhDedKTnaHONmBIjUHM18m9yAV3AS1KBeF0/KfFw20JBFtlcmfXFP1DQyyttkwBnQrY785EE1+xX69Vr/PX05shRUtBN2NDTl7o8SyxluClwZhD9BxJbPn6ILulG3g3HROKm+4Zb1P3IcElzpRyMQ== root@SKA-DEV'

# ---- Root check ----
if [[ $EUID -ne 0 ]]; then
  CMD="sudo bash \"$0\""
  MSG="This installer must be run as root.\n\nWould you like to re-run this script as root?\n\nA following command will be attempted:\n$CMD"
  if whiptail --title "Root Privileges Required" --yesno "$MSG" 14 60; then
    echo "[-] [SKA] Re-running as root: $CMD" >&2
    exec sudo bash "$0" "$@"
  else
    echo "[-] [SKA] Aborted by user: not running as root" >&2
    exit 1
  fi
fi

# ---- Final status reporting (whiptail) ----
FINAL_TASK_NAME="SSH Key Authority (SKA) Setup"
VERIFY_FAILED=0
_report_final_status() {
  local rc=$?
  trap - EXIT
  if [[ $rc -eq 0 ]] && [[ "$VERIFY_FAILED" == "1" ]]; then
    rc=1
  fi
  if command -v whiptail >/dev/null 2>&1; then
    if [[ $rc -eq 0 ]]; then
      whiptail --title "${FINAL_TASK_NAME} — Success" --msgbox \
"[OK] ${FINAL_TASK_NAME} completed successfully.

Review the terminal output above for details." 12 70 || true
    else
      whiptail --title "${FINAL_TASK_NAME} — Failure" --msgbox \
"[X] ${FINAL_TASK_NAME} did NOT complete successfully.
Exit code: ${rc}

Review the terminal output above for error details." 14 70 || true
    fi
  fi
  exit $rc
}
trap _report_final_status EXIT

# ---- Configuration Menu ----
configure_menu() {
  # Check if whiptail is available
  if ! command -v whiptail >/dev/null 2>&1; then
    echo "[X] [SKA] Error: whiptail is required but not installed." >&2
    exit 1
  fi

  # SSH Key Selection
  KEY_CMD=(whiptail --title "SSH Key Selection" \
    --radiolist "Choose which SSH key to install:" 15 80 2 \
    "PROD" "Production key (root@ska) — managed on ska.brainit.tech" ON \
    "DEV" "Development key (root@SKA-DEV) — managed on dev-ska.brainit.tech" OFF)
  
  if ! KEY_CHOICE=$("${KEY_CMD[@]}" 3>&1 1>&2 2>&3); then
    echo "[-] [SKA] Configuration cancelled." >&2
    exit 1
  fi

  # Set SKA_PUBKEY based on selection
  if [[ "$KEY_CHOICE" == "PROD" ]]; then
    SKA_PUBKEY="$SKA_PROD_PUBKEY"
  elif [[ "$KEY_CHOICE" == "DEV" ]]; then
    SKA_PUBKEY="$SKA_DEV_PUBKEY"
  else
    echo "[X] [SKA] Error: Invalid key selection." >&2
    exit 1
  fi

  # Root Login Policy Selection
  ROOT_LOGIN_CMD=(whiptail --title "Root Login Policy" \
    --radiolist "Choose root login policy:" 15 60 2 \
    "prohibit-password" "Allow root login with keys only" ON \
    "no" "Disable root login completely" OFF)
  
  if ! ROOT_LOGIN_CHOICE=$("${ROOT_LOGIN_CMD[@]}" 3>&1 1>&2 2>&3); then
    echo "[-] [SKA] Configuration cancelled." >&2
    exit 1
  fi

  ROOT_LOGIN_POLICY="$ROOT_LOGIN_CHOICE"

  # KEEP_HOME_AUTH_KEYS Selection
  KEEP_CMD=(whiptail --title "Keep Home Authorized Keys" \
    --defaultno \
    --yesno "Do you want to retain ~/.ssh/authorized_keys in addition to the central store?" 10 60)
  
  set +e
  "${KEEP_CMD[@]}"
  KEEP_EXIT_CODE=$?
  set -e
  
  if [[ $KEEP_EXIT_CODE -eq 0 ]]; then
    KEEP_HOME_AUTH_KEYS="1"
  elif [[ $KEEP_EXIT_CODE -eq 1 ]]; then
    KEEP_HOME_AUTH_KEYS="0"
  else
    # Exit code 255 or other error (Escape pressed)
    echo "[-] [SKA] Configuration cancelled." >&2
    exit 1
  fi
}

# ---- Review and Confirm Settings ----
review_and_confirm() {
  # Format the settings for display
  if [[ "$KEY_CHOICE" == "PROD" ]]; then
    KEY_DISPLAY="Production (root@ska)"
  else
    KEY_DISPLAY="Development (root@SKA-DEV)"
  fi

  if [[ "$ROOT_LOGIN_POLICY" == "prohibit-password" ]]; then
    ROOT_DISPLAY="Keys only (prohibit-password)"
  else
    ROOT_DISPLAY="Disabled (no)"
  fi

  if [[ "$KEEP_HOME_AUTH_KEYS" == "1" ]]; then
    KEEP_DISPLAY="Yes"
  else
    KEEP_DISPLAY="No"
  fi

  # Create review message
  REVIEW_MSG="Please review your configuration settings:

SSH Key: ${KEY_DISPLAY}
Root Login: ${ROOT_DISPLAY}
Keep Home Auth Keys: ${KEEP_DISPLAY}

Proceed with installation?"

  # Show confirmation dialog
  if whiptail --title "Review Settings" \
    --yesno "$REVIEW_MSG" 15 60; then
    echo "[-] [SKA] Proceeding with installation..."
  else
    echo "[-] [SKA] Installation cancelled by user." >&2
    exit 1
  fi
}

configure_menu
review_and_confirm

echo "[-] [SKA] Creating 'keys-sync' user..."
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

echo "[-] [SKA] Adding management public key for 'keys-sync' user..."
# ---- Bootstrap SKA access for 'keys-sync' ----
# File path must be /var/local/keys-sync/keys-sync because AuthorizedKeysFile uses %u
tmpkey="$(mktemp)"
printf '%s\n' "$SKA_PUBKEY" > "$tmpkey"
# Owned by keys-sync, perms 0644 per SKA instructions
install -o "$SYNC_USER" -m 0644 "$tmpkey" "${BASE_DIR}/keys-sync"
rm -f "$tmpkey"

echo "[-] [SKA] Creating status file..."
# ---- Status file (owned by keys-sync, 0644) ----
touch "$STATUS_FILE"
chmod 0644 "$STATUS_FILE"
chown -R "$SYNC_USER" "$BASE_DIR"*

echo "[-] [SKA] Installing sshd configuration snippets..."
# ---- Ensure sshd uses drop-ins; add Include if missing ----
install -d -m 0755 "$DROPIN_DIR"
# Older versions of this script glued the Include onto the config's first line
# ("Include .../*.conf<first line>"), which makes sshd ignore the drop-ins. Split it back.
sed -i -E "1s|^(Include ${DROPIN_DIR}/\*\.conf)([^[:space:]].*)\$|\1\n\2|" "$main_cfg"
if ! grep -Eiq '^\s*Include\s+/etc/ssh/sshd_config\.d/\*\.conf(\s|$)' "$main_cfg"; then #TODO - HAS TO BE AT THE TOP!
  tmpcfg="$(mktemp)"
  { printf 'Include %s/*.conf\n' "$DROPIN_DIR"; cat "$main_cfg"; } > "$tmpcfg"
  cat "$tmpcfg" > "$main_cfg" # overwrite in place to keep owner, mode and SELinux label
  rm -f "$tmpcfg"
fi

# ---- Write our drop-in ----
if [[ "$KEEP_HOME_AUTH_KEYS" = "1" ]]; then
  AUTH_LINE='AuthorizedKeysFile .ssh/authorized_keys .ssh/authorized_keys2 /var/local/keys-sync/%u'
else
  AUTH_LINE='AuthorizedKeysFile /var/local/keys-sync/%u'
fi

# $1: optional extra global line (must come before the Match block)
write_dropin() {
  cat > "${DROPIN_DIR}/${DROPIN_FILE}" <<EOF
# Central SSH key management via SSH Key Authority
${AUTH_LINE}
StrictModes no
PermitUserEnvironment yes
# PubkeyAcceptedAlgorithms +ssh-rsa
${1:+$1
}Match User keys-sync
    PasswordAuthentication no
EOF
}
write_dropin

# ---- Ensure the SFTP subsystem exists (SKA distributes keys over SFTP) ----
# The drop-in above is written without a Subsystem line first, so 'sshd -T' shows only
# what the rest of the config defines. We add our own line only when nothing else does:
# a second definition is a fatal config error on older OpenSSH.
SSHD_BIN="$(command -v sshd || echo /usr/sbin/sshd)"
if sshd_dump="$("$SSHD_BIN" -T 2>/dev/null)"; then
  sftp_cmd="$(awk 'tolower($1)=="subsystem" && $2=="sftp" {print $3; exit}' <<<"$sshd_dump")"
  if [[ -z "$sftp_cmd" ]]; then
    echo "[-] [SKA] sshd has no SFTP subsystem configured; enabling 'internal-sftp'."
    write_dropin 'Subsystem sftp internal-sftp'
  elif [[ "$sftp_cmd" != "internal-sftp" && ! -x "$sftp_cmd" ]]; then
    echo "[!] [SKA] 'Subsystem sftp' points to missing '$sftp_cmd'. Install the sftp server package (e.g. 'openssh-sftp-server') or change that line to 'internal-sftp'." >&2
  fi
fi

cat > ${DROPIN_DIR}/${DROPIN_ROOTLOGIN} <<EOF
PermitRootLogin ${ROOT_LOGIN_POLICY}
Match User root
    PasswordAuthentication no
EOF

chmod 0644 "${DROPIN_DIR}/"*
chmod 0644 "$main_cfg"

echo "[-] [SKA] Attempt to validate sshd configuration..."
# ---- Validate config before (re)load ----
"$SSHD_BIN" -t

echo "Reloading ssh daemon..."
# ---- Reload / restart sshd safely across distros ----
if command -v systemctl >/dev/null 2>&1; then
  systemctl try-reload-or-restart sshd.service 2>/dev/null || true
  systemctl try-reload-or-restart ssh.service 2>/dev/null || true
  systemctl try-reload-or-restart openssh-server.service 2>/dev/null || true
else
  if [[ -x /etc/init.d/sshd ]]; then /etc/init.d/sshd reload || /etc/init.d/sshd restart || true; fi
  if [[ -x /etc/init.d/ssh  ]]; then /etc/init.d/ssh  reload || /etc/init.d/ssh  restart  || true; fi
fi

echo "Checking SELinux..."
selinux_present=false
selinux_enforcing=false

# Check if SELinux filesystem is mounted (most reliable indicator)
if [[ -d /sys/fs/selinux ]] && [[ -f /sys/fs/selinux/enforce ]]; then
  selinux_present=true
  # Check if SELinux is enabled (not disabled)
  if [[ "$(cat /sys/fs/selinux/enforce 2>/dev/null)" == "1" ]]; then
    selinux_enforcing=true
  fi
fi

# Fallback: check getenforce command if filesystem check didn't work
if [[ "$selinux_present" == "false" ]] && command -v getenforce >/dev/null 2>&1; then
  enforce_status="$(getenforce 2>/dev/null || echo "Disabled")"
  case "$enforce_status" in
    "Enforcing")
      selinux_present=true
      selinux_enforcing=true
      ;;
    "Permissive")
      selinux_present=true
      selinux_enforcing=false
      ;;
    "Disabled"|*)
      selinux_present=false
      selinux_enforcing=false
      ;;
  esac
fi

# Apply SELinux context if SELinux is present and enforcing
if [[ "$selinux_present" == "true" ]] && [[ "$selinux_enforcing" == "true" ]]; then
  echo "SELinux is present and enforcing. Applying context labels..."
  
  # Check if semanage is available
  if command -v semanage >/dev/null 2>&1; then
    # Check if context already exists to avoid duplicate entry errors
    if ! semanage fcontext -l 2>/dev/null | grep -q "^/var/local/keys-sync(/\.\*)?[[:space:]].*ssh_home_t"; then
      if semanage fcontext -a -t ssh_home_t "/var/local/keys-sync(/.*)?" 2>/dev/null; then
        echo "[✓] [SKA] Added SELinux file context for /var/local/keys-sync"
      else
        echo "[!] [SKA] Warning: Failed to add SELinux file context (may already exist or require policyutils-selinux package)" >&2
      fi
    else
      echo "[✓] [SKA] SELinux file context already configured"
    fi
  else
    echo "[!] [SKA] Warning: 'semanage' command not found. Install 'policycoreutils-python-utils' or 'policycoreutils-python' to configure SELinux contexts." >&2
  fi
  
  # Apply the context using restorecon
  if command -v restorecon >/dev/null 2>&1; then
    if restorecon -Rv /var/local/keys-sync 2>/dev/null; then
      echo "[✓] [SKA] Applied SELinux context labels to /var/local/keys-sync"
    else
      echo "[!] [SKA] Warning: Failed to restore SELinux context labels" >&2
    fi
  else
    echo "[!] [SKA] Warning: 'restorecon' command not found. Install 'policycoreutils' package." >&2
  fi
elif [[ "$selinux_present" == "true" ]] && [[ "$selinux_enforcing" == "false" ]]; then
  echo "SELinux is present but in Permissive mode. Context labels not required, but applying for consistency..."
  if command -v restorecon >/dev/null 2>&1; then
    restorecon -Rv /var/local/keys-sync 2>/dev/null || true
  fi
else
  echo "SELinux is not present or disabled. Skipping SELinux context configuration."
fi

# ---- Verification: loopback login test for keys-sync (non-fatal) ----
verify_loopback() {
  echo "[-] [SKA] Attempting configuration selftest (ssh login to self)..."
  # prerequisites
  if ! command -v ssh >/dev/null 2>&1; then
    echo "[-] [SKA] Verification skipped: 'ssh' client not found." >&2
    return 0
  fi
  if ! command -v ssh-keygen >/dev/null 2>&1; then
    echo "[-] [SKA] Verification skipped: 'ssh-keygen' not found." >&2
    return 0
  fi

  # Create a temporary keypair
  tmpkey="$(mktemp -u /tmp/ska-verify.XXXXXXXX)"
  if ! ssh-keygen -t ed25519 -N "" -f "$tmpkey" >/dev/null 2>&1; then
    if ! ssh-keygen -t rsa -b 3072 -N "" -f "$tmpkey" >/dev/null 2>&1; then
      echo "[-] [SKA] Verification skipped: unable to create a temporary keypair." >&2
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
  sleep 0.5

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

  # SKA writes the key files over SFTP, so test that too: upload a file into the
  # key directory and remove it again.
  sftp_rc=0
  if [[ $rc -eq 0 ]]; then
    probe="${BASE_DIR}/.ska-verify"
    set +e
    if command -v sftp >/dev/null 2>&1; then
      printf 'put %s %s\nrm %s\n' "${tmpkey}.pub" "$probe" "$probe" \
        | sftp -b - "${SSH_OPTS[@]}" "${SYNC_USER}@localhost" >/dev/null 2>&1
    else
      # No sftp client: at least check that the subsystem starts
      ssh "${SSH_OPTS[@]}" -s "${SYNC_USER}@localhost" sftp </dev/null >/dev/null 2>&1
    fi
    sftp_rc=$?
    set -e
    rm -f "$probe"
  fi

  # Clean up temp block and files
  sed -i "/^${marker_begin}\$/,/^${marker_end}\$/d" "$verify_file" || true
  rm -f "$tmpkey" "${tmpkey}.pub"

  if [[ $rc -eq 0 ]] && [[ $sftp_rc -ne 0 ]]; then
    VERIFY_FAILED=1
    echo "[✓] [SKA] Verification: public-key login via ${BASE_DIR}/%u works for '${SYNC_USER}'."
    echo "[!] [SKA] Verification: SFTP FAILED for '${SYNC_USER}' (exit=$sftp_rc). SKA cannot distribute keys to this host." >&2
    echo "    Tips:" >&2
    echo "     • Check 'sshd -T | grep -i subsystem' and that the sftp-server binary it names exists." >&2
    echo "     • Install the sftp server package (e.g. 'openssh-sftp-server') or use 'Subsystem sftp internal-sftp'." >&2
    echo "     • Check that '${SYNC_USER}' can write to ${BASE_DIR}." >&2
  elif [[ $rc -eq 0 ]]; then
    echo "[✓] [SKA] Verification: SUCCESS — public-key login and SFTP via ${BASE_DIR}/%u work for '${SYNC_USER}'."
  else
    VERIFY_FAILED=1
    echo "[!] [SKA] Verification: FAILED (ssh exit=$rc)." >&2
    echo "    Tips:" >&2
    echo "     • Check sshd logs: 'journalctl -u sshd --since -5m' (or /var/log/auth.log or /var/log/secure)." >&2
    echo "     • If SELinux is Enforcing, check it got configured by the script correctly." >&2
    echo "     • Ensure firewall allows localhost:22 and sshd is listening (ss -ltnp | grep :22)." >&2
    echo "====== ATTENTION !!! ATTENTION !!! ATTENTION !!! ATTENTION !!! ATTENTION !!! ATTENTION !!! ATTENTION !!! ======" >&2
    echo "SSH configuration validation check has failed. DO NOT EXIT YOUR CURRENT SSH SESSION!!! You might not be abe to ssh into this system again!" >&2
    echo "You might not be abe to ssh into this system again until you fix the configuration!" >&2
    echo ">>>> DO NOT EXIT YOUR CURRENT SSH SESSION!!! <<<<" >&2
    echo "====== YOU HAVE BEEN WARNED. ======" >&2

    # Also display the warning message in whiptail if available
    if command -v whiptail >/dev/null 2>&1; then
      whiptail --title "SSH Configuration Validation Failed!" \
        --msgbox "======  ATTENTION!  ======\n\
        \nSSH configuration validation check has FAILED.\n\
        \nDO NOT EXIT YOUR CURRENT SSH SESSION!\n\
        \nYou might not be able to ssh into this system again until you fix the configuration!\n\
        \n>>>> DO NOT EXIT YOUR CURRENT SSH SESSION! <<<<\n\n======  YOU HAVE BEEN WARNED.  ======" 16 65
    fi
  fi
}

verify_loopback

if [[ "$VERIFY_FAILED" == "1" ]]; then
  echo "[!] [SKA] SSH Key Authority setup finished, but verification FAILED — see above. Central path: ${BASE_DIR}/%u" >&2
else
  echo "[✓] [SKA] SSH Key Authority setup complete. Central path: ${BASE_DIR}/%u"
fi
