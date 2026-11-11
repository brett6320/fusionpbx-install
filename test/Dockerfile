# Throwaway Debian with systemd as PID 1, for running the full installer.
ARG DEBIAN_RELEASE=trixie
FROM debian:${DEBIAN_RELEASE}

ENV container=docker DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
	&& apt-get install -y systemd systemd-sysv dbus lsb-release ca-certificates curl git sudo procps iproute2 \
	&& apt-get clean \
	&& rm -f /lib/systemd/system/multi-user.target.wants/getty* \
	&& systemctl mask systemd-logind.service getty.target

STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
