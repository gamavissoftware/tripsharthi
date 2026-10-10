#!/usr/bin/env bash
# READ-ONLY: shows what is already installed/running on the server so the new deployment can be fitted around it. Changes nothing.
#   curl -fsSL https://raw.githubusercontent.com/gamavissoftware/tripsharthi/main/deploy/inspect-server.sh | bash
# Passwords found in config files are masked in the output.
mask() { sed -E 's/((pass(word)?|secret|key|token)[A-Za-z_.]* *[=:] *).*/\1***masked***/I'; }
echo "== OS / resources"; . /etc/os-release; echo "$PRETTY_NAME"; free -m | sed -n 2p; df -h / | tail -1
echo "== listening ports"; ss -ltnp 2>/dev/null | awk 'NR==1 || /:(80|443|8080|3306|9000|5432) /' | cut -c1-120
echo "== services"; for s in caddy nginx apache2 php8.3-fpm php8.2-fpm php8.1-fpm mysql mariadb; do printf '%-12s %s\n' $s "$(systemctl is-active $s 2>/dev/null)"; done
echo "== php / node / composer"; php -v 2>/dev/null | head -1; node -v 2>/dev/null; composer --version 2>/dev/null | head -1
echo "== Caddyfile"; cat /etc/caddy/Caddyfile 2>/dev/null | mask | head -80
echo "== nginx sites"; ls /etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null; for f in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do [ -f "$f" ] && { echo "--- $f"; mask < "$f" | head -60; }; done
echo "== app folders"; ls -la /var/www 2>/dev/null; for d in /var/www/*; do [ -d "$d/.git" ] && echo "$d git: $(git -C "$d" log --oneline -1 2>/dev/null)"; done
echo "== databases"; mysql -e 'show databases' 2>&1 | head -15
echo "== cron"; ls /etc/cron.d 2>/dev/null; crontab -l 2>/dev/null | head -20; crontab -u www-data -l 2>/dev/null | head -20
echo "== firewall"; ufw status 2>/dev/null | head -8
