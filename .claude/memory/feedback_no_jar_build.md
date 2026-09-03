---
name: JAR build rules — never manually as root
description: JAR builds are needed for VPS distribution but must NEVER be run manually as root
type: feedback
---

Never run `mvn package` or `mvn clean package` manually (especially as root).

**Why:** Running as root leaves `ipms_java/target/` owned by root, which breaks future portal-triggered builds because `www-data` (PHP-FPM) cannot write to root-owned directories.

**How to apply:**
- For local development / portal machine bot: run `mvn compile` only — portal runs bot via `mvn exec:java`
- For VPS distribution: use the portal "Rebuild JAR" button (`POST /api/bot/package`) — this runs as `www-data` with proper ownership
- If root accidentally ran mvn: fix with `sudo chown -R www-data:www-data /var/www/html/ipms_web/ipms_java/`
