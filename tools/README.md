# How to open an Eleva CRM backup

**Your computer is gone. You have a backup file and you remember your vault password.
This page gets your data back.**

You do not need WordPress. You do not need the plugin. You do not need your old
computer, your old website, or an internet connection. You need the backup file,
your password, and about ten minutes.

---

## What you need in front of you

1. **The backup file**: a `.zip` named something like
   `eleva-backup-2026-08-11-120000-ab12cd34.zip`, downloaded from your Google Drive.
2. **Your vault password.**
3. **Your phone**, with the authenticator app you used to unlock the vault (for the
   6-digit code).

Lost the password or the phone? Skip to [If you lost your password or your phone](#if-you-lost-your-password-or-your-phone).

---

## Step 1: Install PHP

PHP is the free program that runs this tool. Pick your system:

**Mac**

Open the **Terminal** app (press ⌘+Space, type `Terminal`, press Enter) and paste:

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
brew install php
```

**Windows**

Download the zip from [windows.php.net/download](https://windows.php.net/download)
(pick "Thread Safe", the x64 zip), unpack it to `C:\php`, then open **PowerShell** and run:

```powershell
cd C:\php
.\php.exe -v
```

If that prints a version number, PHP works. Use `C:\php\php.exe` everywhere this page
says `php`.

**Linux**

```bash
sudo apt install php-cli php-zip     # Debian, Ubuntu
sudo dnf install php-cli php-sodium  # Fedora, RHEL
```

Check it worked: this should print something like `PHP 8.3.6`:

```bash
php -v
```

---

## Step 2: Put the two files together

Make a new folder, for example `restore` on your Desktop, and put both of these in it:

- your backup `.zip`
- `eleva-backup-decrypt.php` (this file's neighbour: it is inside the plugin at
  `eleva-crm-for-photographers/tools/`, and you can also copy it out of any copy of
  the plugin, or download the plugin again from WordPress.org)

---

## Step 3: Run it

In Terminal (Mac/Linux) or PowerShell (Windows), go to that folder and run the tool
with the name of your backup file:

```bash
cd ~/Desktop/restore
php eleva-backup-decrypt.php eleva-backup-2026-08-11-120000-ab12cd34.zip
```

It will:

1. check the archive is undamaged,
2. ask for your **vault password** (nothing appears as you type, that is normal),
3. ask for the **6-digit code** from your authenticator app,
4. write everything into a new `out` folder.

That's it. Your data is in `out`.

---

## What you get

| File | What it is |
|---|---|
| `out/data.json` | Every record: customers, works, services, memory cards, settings. A text file: open it with any text editor, or give it to whoever rebuilds your site. |
| `out/files/` | Your attachments (contracts, invoices, PDFs, photos), decrypted and byte-for-byte identical to the originals. |
| `out/manifest.json` | A copy of the archive's inventory: what was in it, how big, and the checksums. |

Personal details inside `data.json` (names, emails, phone numbers, addresses) stay
encrypted exactly as the plugin stored them in the database. That is deliberate: the
records are ready to be loaded straight back into a WordPress site with your vault,
without your customers' details ever sitting in a plain text file. Work titles,
prices, notes, dates and payment status are readable.

---

## If you lost your password or your phone

When you first set up the vault, the plugin showed you a **recovery code**
(`ABCD-2345-WXYZ-FG7H-MNPQ`) and a **recovery phrase** (eight groups of six
characters). Either one replaces the password:

```bash
php eleva-backup-decrypt.php backup.zip --recovery-code=ABCD-2345-WXYZ-FG7H-MNPQ
```

```bash
php eleva-backup-decrypt.php backup.zip --recovery-phrase="9MZYCH-VMHTWG-7KWWDK-9Q9BQP-X9NW62-VBFKEX-9FG73W-93Q07D"
```

Dashes, spaces and capitals do not matter, type it however you wrote it down.

You will still be asked for the 6-digit code, so you also need the authenticator app.

**If the password, the recovery code, the recovery phrase and the authenticator are
all gone, the archive cannot be opened.** Not by this tool, not by us, not by anyone.
That is the same guarantee that keeps your customers' data safe from whoever steals
the backup: it cannot be true in one direction only. Look properly for the recovery
code before giving up: it is often in a password manager, a printout, or a photo.

---

## Checking a backup without opening it

Good habit, and it needs no password at all:

```bash
php eleva-backup-decrypt.php backup.zip --verify-only
```

This checks every part of the archive against its checksums and tells you whether the
file is intact and complete. Nothing is written to disk and you are never asked for
anything. Do this once when a backup lands in your Drive, and you will never be
surprised on the day you actually need it.

---

## When something goes wrong

| What you see | What it means | What to do |
|---|---|---|
| `Wrong password` | The password did not open the archive. | Check caps lock and keyboard layout. Try your recovery code. |
| `Wrong OTP` | The 6-digit code did not match. | Wait for the app to show a fresh code, then retype it. If it keeps failing, check this computer's clock is set correctly. |
| `archive corrupted at ...` | The file was damaged, most often by an incomplete download. | Download the archive from Google Drive again. |
| `This archive is missing its key material (key.json)` | The archive was modified after it was written, or it is not complete. | Use a different copy of the backup. |
| `This PHP is missing the sodium extension` | PHP was installed without the parts the encryption needs. | Install the standard PHP package for your system (see Step 1) rather than a stripped-down build. |
| `out already exists and is not empty` | You already restored once into that folder. | Use `--out=another-folder`, or add `--force`. |

More options: `php eleva-backup-decrypt.php --help`.

---

## Two things to know

**The `out` folder is not protected.** Once decrypted, your attachments and records
sit there in the clear. Keep it on a drive you control, and delete it when you are
done. The tool creates it readable only by you.

**What a backup does and does not contain.** It contains the plugin's data:
customers, works, services, memory cards, tasks, the settings that matter, and every
file attached to a work. It does not contain your WordPress site itself: themes,
other plugins, pages, or the site design, and it never contains your SMTP password
or your Google Calendar connection, because those are tied to the old server and
would not work anywhere else. You re-enter those two by hand after a restore.

---

## For the technically minded

The tool is a single PHP file with no dependencies: no Composer, no WordPress, no
plugin code, no network access. It reimplements the archive format from the
specification, so it doubles as an independent check that the format is correct
rather than merely self-consistent.

Chain: `PBKDF2-SHA256(password, salt, 600k)` → unwraps the master key from
`vault_wrap_pw` (AES-256-GCM) → unwraps the TOTP secret and the backup private key →
`sodium_crypto_box_seal_open()` recovers the archive key → the payload streams out of
AES-256-GCM records that are individually authenticated and bound to their position,
so reordering, truncation and splicing all fail loudly. Everything needed lives in
the archive, which is why it opens on any machine with any server salts.

Exit codes: `0` success, `1` usage or environment, `2` archive problem,
`3` wrong credentials.

For automation, `ELEVA_BACKUP_PASSWORD` and `ELEVA_BACKUP_OTP` are read from the
environment, and `--password-stdin` reads the password from standard input.
`--data-only` skips attachments.
