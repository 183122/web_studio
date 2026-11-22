# Studio SPA build + nginx edge (serves the SPA, proxies /api and /sanctum to php-fpm).
FROM node:22-alpine AS build
WORKDIR /src
COPY package.json package-lock.json ./
COPY packages packages
COPY apps/studio-web apps/studio-web
COPY tests/e2e/package.json tests/e2e/package.json
RUN npm ci --no-audit --no-fund && npm run build -w apps/studio-web

FROM nginx:1.27-alpine
COPY infra/docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /src/apps/studio-web/dist /usr/share/nginx/html
EXPOSE 8080
