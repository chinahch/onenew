# Onenew overlays the exact audited upstream app image; it never reclones a moving branch.
FROM ghcr.io/cedar2025/xboard@sha256:493c71b7c991c40eb3e114ccc6cbeda89d42b31e5a6341dd3eb9d7929dd7c13f AS upstream

FROM --platform=$BUILDPLATFORM node:24.22.1-alpine AS admin-build
WORKDIR /build/admin
RUN corepack enable && corepack prepare pnpm@11.25.0 --activate
COPY customizations/onenew/admin/package.json customizations/onenew/admin/pnpm-lock.yaml customizations/onenew/admin/pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile
COPY customizations/onenew/admin/ ./
COPY --from=upstream /www/public/assets/admin /build/upstream-admin
RUN ONENEW_ADMIN_UPSTREAM=/build/upstream-admin pnpm test && pnpm build --upstream-dir /build/upstream-admin

FROM --platform=$BUILDPLATFORM node:24.22.1-alpine AS user-build
WORKDIR /build/user
RUN corepack enable && corepack prepare pnpm@11.25.0 --activate
COPY customizations/onenew/user-portal/package.json customizations/onenew/user-portal/pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
COPY customizations/onenew/user-portal/ ./
RUN pnpm test && pnpm build

FROM upstream AS runtime
WORKDIR /www
ARG ONENEW_REVISION=local
COPY customizations/onenew /opt/onenew
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
