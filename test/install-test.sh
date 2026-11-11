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

echo "== install"
docker exec "$name" bash /usr/src/x-install/install.sh

echo "== checks"
docker exec -i "$name" bash -s < test/checks.sh

echo "== upgrade (idempotency)"
docker exec "$name" bash /usr/src/x-install/upgrade.sh
docker exec -i "$name" bash -s < test/checks.sh
