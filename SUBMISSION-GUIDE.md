# Fotonic — WordPress.org Submission Guide

## Pre-Submission Checklist

Before submitting, verify:

- [ ] `fotonic.php` Version matches `readme.txt` Stable tag (currently both `1.3.1`)
- [ ] `readme.txt` "Tested up to" reflects the WordPress version you last tested against
- [ ] All 4 screenshots listed in readme.txt exist in SVN `/assets/` folder
- [ ] Banner and icon images are in SVN `/assets/` folder
- [ ] Run `npm run build` in `src/` and commit `dist/` before tagging
- [ ] Validate readme.txt at https://wordpress.org/plugins/developers/readme-validator/

---

## Required Assets

Assets are uploaded to a **separate `/assets/` folder in SVN** — they are NOT inside the plugin ZIP.

| Asset | Filename | Size | Format | Notes |
|-------|----------|------|--------|-------|
| Plugin icon | `icon-128x128.png` | 128 × 128 px | PNG | Square, transparent bg OK |
| Plugin icon (retina) | `icon-256x256.png` | 256 × 256 px | PNG | Same design at 2× |
| Plugin banner | `banner-772x250.png` | 772 × 250 px | PNG or JPG | Standard banner |
| Plugin banner (retina) | `banner-1544x500.png` | 1544 × 500 px | PNG or JPG | HiDPI banner |
| Screenshot 1 | `screenshot-1.png` | Max 1200 px wide | PNG or JPG | Vault unlock screen |
| Screenshot 2 | `screenshot-2.png` | Max 1200 px wide | PNG or JPG | Dashboard |
| Screenshot 3 | `screenshot-3.png` | Max 1200 px wide | PNG or JPG | Customer list |
| Screenshot 4 | `screenshot-4.png` | Max 1200 px wide | PNG or JPG | Work detail form |

**Where to save them locally:** Place them in `.wordpress-org/` at the plugin root before committing to SVN.

---

## Step 1 — Submit Plugin for Review

1. Go to https://wordpress.org/plugins/developers/add/
2. Log in with your wordpress.org account (username: `ateleva`)
3. Submit the plugin ZIP. Use the `.distignore` file to generate a clean zip:
   ```bash
   # From plugin root
   cd /Users/alessandro/Local\ Sites/fotonic/app/public/wp-content/plugins/fotonic
   zip -r fotonic.zip . -x@.distignore
   ```
4. WP.org review team will email you (can take 1–4 weeks)
5. Once approved, you receive your SVN repository URL:
   `https://plugins.svn.wordpress.org/fotonic/`

---

## Step 2 — Set Up SVN Repository

After approval, check out the SVN repo:

```bash
svn checkout https://plugins.svn.wordpress.org/fotonic fotonic-svn
cd fotonic-svn
ls
# trunk/   tags/   assets/
```

---

## Step 3 — First Commit (Initial Deploy)

Copy plugin files into SVN trunk (exclude dev files):

```bash
# From the SVN checkout directory
rsync -av --exclude-from="/Users/alessandro/Local Sites/fotonic/app/public/wp-content/plugins/fotonic/.distignore" \
  "/Users/alessandro/Local Sites/fotonic/app/public/wp-content/plugins/fotonic/" \
  trunk/

# Add all new files to SVN
svn add trunk/* --force

# Commit
svn commit -m "Initial release v1.3.1"
```

---

## Step 4 — Upload Assets to SVN

Assets go in the `/assets/` SVN folder (separate from `/trunk/`):

```bash
# Copy assets into SVN assets folder
cp .wordpress-org/icon-128x128.png     assets/
cp .wordpress-org/icon-256x256.png     assets/
cp .wordpress-org/banner-772x250.png   assets/
cp .wordpress-org/banner-1544x500.png  assets/
cp .wordpress-org/screenshot-1.png     assets/
cp .wordpress-org/screenshot-2.png     assets/
cp .wordpress-org/screenshot-3.png     assets/
cp .wordpress-org/screenshot-4.png     assets/

svn add assets/*
svn commit -m "Add plugin assets: banner, icon, screenshots"
```

---

## Step 5 — Create a Release Tag

Tags trigger WP.org to make the version downloadable:

```bash
# Copy trunk to a version tag
svn copy trunk tags/1.3.1

# Commit the tag
svn commit -m "Tag release 1.3.1"
```

After this commit, WP.org will process and serve version 1.3.1 as the stable download.

---

## Future Release Workflow

For each new version (e.g. `1.4.0`):

1. Update `Version:` in `fotonic.php`
2. Update `Stable tag:` in `readme.txt`
3. Add changelog entry in `readme.txt` == Changelog ==
4. Run `npm run build` in `src/`, commit `dist/` to git
5. Push to GitHub (CI builds verify the dist)
6. Sync to SVN trunk and tag:

```bash
# Sync trunk
rsync -av --exclude-from=".distignore" \
  "/Users/alessandro/Local Sites/fotonic/app/public/wp-content/plugins/fotonic/" \
  trunk/

svn status trunk/           # check what changed
svn add trunk/NEW_FILE      # add any new files
svn delete trunk/OLD_FILE   # remove deleted files

svn commit -m "Update trunk to v1.4.0"

# Create version tag
svn copy trunk tags/1.4.0
svn commit -m "Tag release 1.4.0"
```

---

## SVN Folder Structure Reference

```
fotonic-svn/
├── trunk/              ← Current development version (what gets installed)
│   ├── fotonic.php
│   ├── readme.txt
│   ├── uninstall.php
│   ├── includes/
│   ├── dist/
│   └── languages/
├── tags/               ← Versioned snapshots (each = a downloadable release)
│   ├── 1.3.1/
│   └── 1.4.0/
└── assets/             ← Banner, icon, screenshots (NOT in plugin ZIP)
    ├── banner-772x250.png
    ├── banner-1544x500.png
    ├── icon-128x128.png
    ├── icon-256x256.png
    ├── screenshot-1.png
    ├── screenshot-2.png
    ├── screenshot-3.png
    └── screenshot-4.png
```

---

## Notes

- `Stable tag` in readme.txt controls which version users download. Always update it.
- Assets in `/assets/` are displayed on the plugin page but are NOT included in the plugin ZIP.
- WP.org SVN is separate from your GitHub repo — you maintain both.
- The GitHub Actions workflow (`.github/workflows/deploy.yml`) can automate SVN syncing once `SVN_USERNAME` and `SVN_PASSWORD` secrets are set.
- Plugin page URL after approval: https://wordpress.org/plugins/fotonic/
