# Multmec Financeiro: imagem enxuta (Node + SQLite em arquivo).
FROM node:22-slim
ENV NODE_ENV=production
WORKDIR /app
COPY package.json package-lock.json ./
# build tools só para compilar o better-sqlite3 caso não exista binário pronto para a arquitetura (ex.: ARM)
RUN apt-get update && apt-get install -y --no-install-recommends python3 make g++ \
    && npm ci --omit=dev \
    && apt-get purge -y python3 make g++ && apt-get autoremove -y && rm -rf /var/lib/apt/lists/*
COPY src ./src
COPY public ./public
COPY scripts ./scripts
# O banco fica em /data: monte um volume persistente aqui (senão os dados somem ao reiniciar).
ENV DB_PATH=/data/multmec.db PORT=3000 TRUST_PROXY=1
RUN mkdir -p /data && chown node:node /data
VOLUME /data
EXPOSE 3000
USER node
CMD ["node", "src/server.js"]
