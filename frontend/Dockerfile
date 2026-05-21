# ── Etapa 1: build ──────────────────────────────────────────────────────────
FROM node:20-alpine AS builder

WORKDIR /app
COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build

# ── Etapa 2: serve con nginx ─────────────────────────────────────────────────
FROM nginx:alpine

# Copiar el build
COPY --from=builder /app/dist /usr/share/nginx/html

# Config nginx: SPA routing + proxy /api/ al backend PHP
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
