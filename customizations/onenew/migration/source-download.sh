# Shared source downloader. This function is embedded into standalone entry scripts.
onenew_download_source() {
  local revision="$1" output="$2" stage="$3" token_file="${4:-${HOME}/.config/onenew/github.token}"
  local token='' config='' mode='' result=0
  [[ "$revision" =~ ^[0-9a-f]{40}$ ]] || return 1
  if [ -e "$token_file" ] || [ -L "$token_file" ]; then
    [ -f "$token_file" ] && [ ! -L "$token_file" ] || { echo 'GitHub credential must be a regular private file.' >&2; return 1; }
    mode=$(stat -c '%a' "$token_file") || return 1
    { [ "$mode" = 600 ] || [ "$mode" = 400 ]; } && [ "$(stat -c '%u' "$token_file")" = "$(id -u)" ] || { echo 'GitHub credential must belong to the current user with mode 600 or 400.' >&2; return 1; }
    [ "$(wc -c < "$token_file")" -le 512 ] || return 1
    token=$(cat -- "$token_file")
    [[ ${#token} -ge 16 && ${#token} -le 256 && "$token" != *[!A-Za-z0-9_]* ]] || { echo 'Invalid GitHub credential file.' >&2; return 1; }
    config=$(mktemp "$stage/onenew-auth.XXXXXXXX.curl") || return 1
    chmod 600 "$config"
    printf 'user = "x-access-token:%s"\n' "$token" > "$config"
    unset token
    # Basic credentials are scoped to api.github.com. curl strips them on the
    # cross-host codeload redirect; never use location-trusted or an auth header.
    curl --disable --config "$config" --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 --max-time 180 \
      "https://api.github.com/repos/chinahch/onenew/tarball/$revision" -o "$output" || result=$?
    rm -f -- "$config"
  else
    curl --disable --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 --max-time 180 \
      "https://github.com/chinahch/onenew/archive/$revision.tar.gz" -o "$output" || result=$?
  fi
  if [ "$result" -ne 0 ]; then
    echo 'Source download failed. For a private source repository, save its read-only GitHub credential in ~/.config/onenew/github.token (mode 600), then retry. Existing deployment data was not changed.' >&2
    return "$result"
  fi
}
