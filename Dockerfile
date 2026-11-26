# Onenew overlays the exact audited upstream app image; it never reclones a moving branch.
FROM ghcr.io/cedar2025/xboard@sha256:493c71b7c991c40eb3e114ccc6cbeda89d42b31e5a6341dd3eb9d7929dd7c13f AS upstream

FROM --platform=$BUILDPLATFORM node:24.22.1-alpine@sha256:06d7adf3e0ef61a13f1d28682726366f97a15aa1888c934d3ecec86c33918b53 AS admin-build
WORKDIR /build/admin
RUN corepack enable && corepack prepare pnpm@11.25.0 --activate
COPY customizations/onenew/admin/package.json customizations/onenew/admin/pnpm-lock.yaml customizations/onenew/admin/pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile
COPY customizations/onenew/admin/ ./
# The base image cloned a moving admin submodule at build time. Use the exact
# independently verified distribution instead of trusting that embedded copy.
ADD --checksum=sha256:2f163cb99195d9176ccceadbd71a1b85259f3ba3dbe56646012d67287ffb0214 https://codeload.github.com/cedar2025/xboard-admin-dist/tar.gz/ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7 /tmp/onenew-admin.tar.gz
RUN mkdir /build/upstream-admin && tar -xzf /tmp/onenew-admin.tar.gz --strip-components=1 -C /build/upstream-admin
RUN ONENEW_ADMIN_UPSTREAM=/build/upstream-admin pnpm test && pnpm build --upstream-dir /build/upstream-admin

FROM --platform=$BUILDPLATFORM node:24.22.1-alpine@sha256:06d7adf3e0ef61a13f1d28682726366f97a15aa1888c934d3ecec86c33918b53 AS user-build
WORKDIR /build/user
RUN corepack enable && corepack prepare pnpm@11.25.0 --activate
COPY customizations/onenew/user-portal/package.json customizations/onenew/user-portal/pnpm-lock.yaml customizations/onenew/user-portal/pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile
COPY customizations/onenew/user-portal/ ./
RUN pnpm test && pnpm build

FROM alpine:3.22@sha256:49340b346798875b5592e5d420241f2f9cfba5f47e62ebb1446a1b34734e898f AS su-exec-build
RUN apk add --no-cache su-exec

FROM upstream AS runtime
WORKDIR /www
COPY --from=su-exec-build /sbin/su-exec /sbin/su-exec
ARG ONENEW_REVISION=local
COPY customizations/onenew /opt/onenew
# Immutable local IPv4/IPv6 lookup data; no end-user IP is sent off-server.
ADD --checksum=sha256:a12ad5aca1277d67e525efcda531a20cc0d654ff4f087150d45bc17fca4bc260 https://raw.githubusercontent.com/lionsoul2014/ip2region/200f4cc7a6452843ef515a889c72d295281e8da3/data/ip2region_v4.xdb /opt/onenew/geoip/ip2region_v4.xdb
ADD --checksum=sha256:1f0be1ffbbc3e4eeef10f354e01355f22f57ec649a496a17fd602539f3d384c1 https://raw.githubusercontent.com/lionsoul2014/ip2region/200f4cc7a6452843ef515a889c72d295281e8da3/data/ip2region_v6.xdb /opt/onenew/geoip/ip2region_v6.xdb
RUN chmod 0644 /opt/onenew/geoip/*.xdb
COPY --from=admin-build /build/admin/dist/ /www/public/assets/admin/
COPY --from=user-build /build/user/dist/ /www/public/onenew-user/
RUN printf '%s\n' "$ONENEW_REVISION" > /opt/onenew/REVISION \
    && php /opt/onenew/install.php /www \
    && php artisan config:clear \
    && php artisan route:clear
ENV APP_NAME=onenew
LABEL org.opencontainers.image.title="onenew" \
      org.opencontainers.image.source="https://github.com/chinahch/onenew" \
      org.opencontainers.image.licenses="MIT"
EXPOSE 7001
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
