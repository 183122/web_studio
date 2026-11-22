# Studio SPA + published-site runtime bundle + nginx (studio host and tenant hosts; see nginx.conf).
FROM node:22-alpine AS build
WORKDIR /src
COPY package.json package-lock.json ./
COPY packages packages
COPY apps/studio-web apps/studio-web
COPY apps/runtime-web apps/runtime-web
COPY tests/e2e/package.json tests/e2e/package.json
RUN npm ci --no-audit --no-fund && npm run build -w apps/studio-web && mkdir -p apps/platform-api/public && npm run build -w apps/runtime-web

FROM nginx:1.27-alpine
COPY infra/docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /src/apps/studio-web/dist /usr/share/nginx/html
COPY --from=build /src/apps/platform-api/public/runtime /usr/share/nginx/runtime
EXPOSE 8080
