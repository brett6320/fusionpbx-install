#!/bin/bash
# Run the full installer in a throwaway systemd container, then check the result.
# FreeSWITCH builds from source, so expect this to take a long time.
#
# Usage: test/install-test.sh [bookworm|trixie] [--keep]
set -euo pipefail
cd "$(dirname "$0")/.."

release=${1:-trixie}
keep=${2:-}
name=x-fusionpbx-test-$release
image=x-fusionpbx-test:$release

cleanup() {
	[ "$keep" = "--keep" ] && { echo "Container $name kept."; return; }
	docker rm -f "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker build --build-arg DEBIAN_RELEASE="$release" -t "$image" test/
docker rm -f "$name" >/dev/null 2>&1 || true
docker run -d --name "$name" --privileged --cgroupns=host \
	-v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock \
	"$image"

#copy the working tree in (not a mount, so the installer can't touch the host)
docker exec "$name" mkdir -p /usr/src/x-install
COPYFILE_DISABLE=1 tar --no-xattrs --exclude=.git -cf - . | docker exec -i "$name" tar -xf - -C /usr/src/x-install

echo "== preflight refuses when outbound port 80 is blocked, without changing the host"
if docker exec "$name" iptables -I OUTPUT -p tcp --dport 80 -j REJECT 2>/dev/null; then
	if out=$(timeout 600 docker exec "$name" bash /usr/src/x-install/install.sh 2>&1); then
		echo "FAIL  install.sh ran with port 80 blocked"; exit 1
	fi
	docker exec "$name" iptables -D OUTPUT -p tcp --dport 80 -j REJECT
	grep -q "Cannot reach the URLs above" <<<"$out" || { printf '%s\n' "$out" | tail -n 5; echo "FAIL  preflight message"; exit 1; }
	docker exec "$name" bash -c 'test ! -e /var/www/fusionpbx && test ! -e /etc/fusionpbx && test ! -e /usr/src/fusionpbx-install.sh && ! compgen -G "/root/x-install-*.log" >/dev/null' \
		|| { echo "FAIL  preflight changed the host"; exit 1; }
	echo "PASS  preflight refused before changing the host"
else
	echo "WARN  cannot add an iptables rule in this container; preflight refusal not tested"
fi

echo "== install"
docker exec "$name" bash /usr/src/x-install/install.sh

echo "== checks"
docker exec -i "$name" bash -s < test/checks.sh

echo "== upgrade (idempotency)"
docker exec "$name" bash /usr/src/x-install/upgrade.sh
docker exec -i "$name" bash -s < test/checks.sh

echo "== optional apps update as www-data"
#upgrade.sh --optional fails on any pull error; also confirm www-data can still pull each checkout itself
docker exec "$name" bash /usr/src/x-install/upgrade.sh --optional
for repo in $(docker exec "$name" bash -c 'for g in /var/www/fusionpbx/app/*/.git; do [ -d "$g" ] && dirname "$g"; done'); do
	if ! docker exec "$name" runuser -u www-data -- git -C "$repo" fetch --dry-run >/dev/null 2>&1; then
		echo "FAIL  www-data cannot fetch in $repo"; exit 1
	fi
done
echo "PASS  optional apps update as www-data"
docker exec -i "$name" bash -s < test/checks.sh
