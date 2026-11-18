# HelpCenter source snapshot — v3.10.10 (v166)

This is a versioned source snapshot assembled from the latest HelpCenter files available in the ChatGPT project library on 2026-11-19.

## Files in this snapshot

- `helpcenter-FIXED-v166.php` — main loader, HelpCenter version 3.10.10.
- `class-hc-ai-FIXED-v166.php` — customer AI/chat widget and conversation restoration updates, v166.
- `class-hc-live-support-FIXED-v164.php` — latest live-support source located in the available uploads, v164.
- `PATCH-NOTES-v166-PAGE-NAVIGATION-STATE-RESTORE.txt` — v166 change notes and validation limitations.

## Important completeness and deployment note

This is **not a complete standalone HelpCenter release archive**. The v166 main loader references other HelpCenter modules, templates, admin files, assets, and dependencies that are not all present as matching v166 files in this snapshot. The live-support implementation available here is v164, not v166. The repository's pre-existing child-theme ZIP remains unchanged.

Do not deploy this folder by itself or replace the production HelpCenter directory with it. Use the original child-theme source archive as the base, reconcile these files into the correct `helpcenter/` paths, and test on staging first.

The v166 patch notes state that the navigation restore change has not been live-tested against WordPress, browser navigation, Ably, or the production database.
