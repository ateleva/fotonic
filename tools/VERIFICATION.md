# Backup archive: verification record

What has actually been proven about the encrypted backup format, by running it,
not by reasoning about it. A future session should read this before re-deriving
anything.

**v1 ships backup only**, restore into WordPress lands in v2, so
`eleva-backup-decrypt.php` *is* the restore path. Every claim below is about
that tool opening real archives.

---

## Run of 2026-08-11 (Phase 5)

Tool version 1.0.0 · plugin 1.3.12 · macOS 15 (Darwin 24.6, arm64)

### Environment

| PHP | Where it came from | Result |
|---|---|---|
| 7.4.33 | Homebrew `shivammathur/php`, keg-only | full matrix passed |
| 8.0.30 | Local by Flywheel bundle | syntax check only |
| 8.2.29 | Local by Flywheel bundle (the site's own PHP) | full matrix passed |
| 8.3.23 | Local by Flywheel bundle | full matrix passed |
| 8.5.8 | Homebrew `php` (system default) | full matrix passed |

The decrypted `data.json` is **byte-identical (SHA-256 `1a2bbfefb00f…`) on
7.4.33, 8.2.29, 8.3.23 and 8.5.8**.

### Archives tested

Real archives built by `Fotonic_Backup_Archive::create()` against the live
fotonic.local database (5 customers, 8 services, 9 works, 8 memory cards, 1 real
attachment).

| Archive | Bytes | SHA-256 |
|---|---:|---|
| `normal-eleva-backup-2026-08-11-154051-01fc7f97.zip` | 35,094 | `7d5fc6023a93a6f403c16f2dbe0f5e8889d442804d2f33bffcae07080ed23c1b` |
| `unlocked-eleva-backup-2026-08-11-154537-5f2cfdfe.zip` | 35,093 | `5e9d4cdd7debaac8e5cd032660318133f840c394d5152b33d7b784a2de778420` |
| `multichunk-eleva-backup-2026-08-11-154537-c07d5fe3.zip` | 3,180,950 | `fc6fb6e6a65e6002a608b5eb31a892f28f0d72fd8d361555045d2fd1c7506a3e` |
| `empty-eleva-backup-2026-08-11-154537-76407e7f.zip` | 3,150 | `94c26e9e2d0d8390855cd6cf23fb3812abef6d9555237ab970be71f74c1854a6` |
| `bigfile-eleva-backup-2026-08-11-155033-6e4e0f48.zip` | 314,619,152 | `b244a83676159be7aed5cba6b12547051627c81d9603fc2d71d61efbb09e97e0` |

`multichunk` carries a synthetic 3 MiB option value purely so `data.enc` spans
several 1 MiB records: the reorder and truncation rows need more than one data
record to be meaningful. `bigfile` carries a temporary 300 MB attachment
(SHA-256 `afb2d437856510ee9f05f8c3faa4976d56d4b53a5d90df60725cec83284cf503`),
deleted from the site afterwards.

**How the test archives were keyed.** Each build ran against a synthetic key
hierarchy injected through `pre_option_*` filters. Read-only, in-process, never
written to the database, this gives archives with a *known* password, recovery
code, recovery phrase and TOTP secret, while the real vault's master key, salts
and wraps are never read, never written and never at risk. Every build asserted
that the live `fotonic_backup_pubkey`, `fotonic_backup_wrap_priv`,
`fotonic_vault_salt`, `fotonic_vault_wrap_pw` and `fotonic_vault_wrap_totp` rows
hashed identically before and after; every run passed that assertion.

---

## The matrix

All 17 rows pass. Rows marked (+) are extra cases added while testing.

| # | Test | Result |
|---|---|---|
| 1 | Decrypt a real archive with the correct password + OTP | **PASS**: `data.json` produced; `ftnc_customer=5, ftnc_service=8, ftnc_work=9, ftnc_memory_card=8`, matching `wp_count_posts()` on the live DB |
| 2 | Cross-machine: decrypt on a second Local site with different `wp-config.php` salts | **PASS**: run inside `hostar-docs` (AUTH_KEY SHA-256 `3722e8d3…` vs fotonic's `e4ab8620…`, different DB, Eleva not installed there). `data.json` and the attachment came out byte-identical to the origin-machine run |
| 3 | Decrypt on a machine with no WordPress at all | **PASS**: isolated directory holding only the tool and the archive, run under `open_basedir` restricted to that directory. The same PHP process could not even see `wp-load.php` (`file_exists()` → blocked), and still produced identical output |
| 4 | Wrong password | **PASS**: exit 3, "Wrong password", fails on the `vault_wrap_pw` GCM tag; no output directory created, no partial file |
| 5 | Correct password, wrong OTP | **PASS**: exit 3, "Wrong OTP", after the master key was recovered but **before** the archive key; `data.enc` never decrypted, no output directory |
| 6 | Recovery code instead of password | **PASS**: `data.json` identical to the password run |
| 7 | Recovery phrase instead of password | **PASS**: `data.json` identical to the password run |
| 8 | Flip one byte in `data.enc` | **PASS**: exit 2, "archive corrupted at data.enc: its SHA-256 does not match the manifest" |
| 8b (+) | Same flipped byte, manifest checksums rewritten to match | **PASS**: exit 2, "archive corrupted at data.enc: authentication failed on record 1"; nothing kept. Proves GCM catches it independently of the checksum gate |
| 9 | Reorder two chunks in `data.enc` | **PASS**: exit 2, "record 0 is out of order, comes from a different stream, or its nonce was altered" |
| 9b (+) | Exchange two records' ciphertext+tag but leave their nonces in position | **PASS**: exit 2, "authentication failed on record 0". This is the case only the AAD counter binding can catch; the positional nonce check passes here by construction |
| 10 | Truncate `data.enc` by one record | **PASS**: exit 2, "the end-of-stream marker is missing"; the partially-written output was discarded, not promoted |
| 10b (+) | Append junk after the terminator | **PASS**: exit 2, "there is data after the end-of-stream marker" |
| 11 | Delete `key.json` | **PASS**: exit 2, "This archive is missing its key material (key.json)…", raised before anything else and before any prompt |
| 12 | Archive containing a 300 MB attachment | **PASS**: extracted byte-identical (SHA-256 matches the source file), under a hard `memory_limit=64M`; max RSS 20.7 MB, 5.6 s wall |
| 13 | Archive built with the vault locked vs unlocked | **PASS**: an archive built while `Fotonic_Vault::is_unlocked()` was genuinely true decrypts to content identical to the locked build (every field but `generated_at`) |
| 14 | `--verify-only` on a good archive | **PASS**: exit 0, no prompt, stdin closed; checksums and key-material shape verified |
| 14b (+) | `--verify-only --no-totp` with a password | **PASS**: full key chain verified and `data.enc` decrypted through to its terminator, with nothing written to disk |
| 14c (+) | `--no-totp` without `--verify-only` | **PASS**: refused, exit 1. Skipping the authenticator is never a way to extract data |
| 15 | Decrypted `data.json` must not contain machine-bound secrets | **PASS**: `fotonic_gcal_refresh_token`, `fotonic_server_secret_fallback`, `fotonic_pro_slm_secret`, `fotonic_smtp_settings`, `fotonic_pro_license_last_valid`, `fotonic_pro_license_expiry` and any `"password":` field are all absent |
| 16 | `manifest.json` must not contain PII | **PASS**: 65 distinct titles, notes and meta values were extracted from the decrypted payload and searched for in the plaintext manifest: zero hits. The literal key `post_title` never appears either |
| 17 | Empty install (zero records) | **PASS**: a valid 3,150-byte archive that decrypts to empty datasets with the options block intact |

### Also checked

- **Interactive use, in a real pty**: password prompt hides typing, a wrong
  password re-prompts (3 attempts), the OTP prompt echoes normally, and the
  terminal's echo setting is restored however the run ends, verified by reading
  `stty` before and after an aborted run.
- **Not runnable over HTTP**: served through `php -S` from inside the plugin
  directory, `eleva-backup-decrypt.php` answers **403 Forbidden** with
  "This tool runs only from the command line." and executes nothing.
- **Independence**: the file contains no `require`/`include` of any plugin file,
  no `wp-load.php`, no `wp_*()` call, no `get_option()`, no `$wpdb`. Confirmed by
  grep and, more strongly, by row 3's `open_basedir` run.
- **Exit codes**: unknown flag → 1, missing file → 1, not-a-zip → 2, bad
  credentials → 3, success → 0.
- **Ships in the release zip**: an rsync driven by `.distignore` puts
  `tools/eleva-backup-decrypt.php` in the built plugin directory.

---

## Known limits of this record

- Row 2 used a second **Local site on the same Mac**, not a physically different
  computer. What it proves is what matters: different `wp-config.php` salts,
  different database, plugin absent, and row 3 goes further by denying the
  process any access to the WordPress installation at all. A genuinely different
  machine has still never been tried.
- `manifest.json` is **not cryptographically authenticated**. An attacker who
  rewrites both a member and its manifest checksum gets past the integrity gate,
  and is then caught by GCM (row 8b). The checksums exist to catch *damage*
  (truncated downloads, bit rot) early and cheaply; the authentication guarantee
  comes from GCM on `data.enc` and `files/*.enc`, plus the `plaintext_sha256`
  comparison the tool performs after decrypting each attachment.
- The tool's messages are English only. It has no WordPress i18n runtime by
  design, so translating them needs its own mechanism if that is ever wanted.
- TOTP replay: the plugin refuses a code that was already used (a transient). The
  offline tool has nowhere to store that, so the same code works twice within its
  60-second window. Irrelevant offline: anyone who can run the tool already holds
  the archive and the password.
