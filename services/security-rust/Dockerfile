FROM rust:1.78 AS builder
WORKDIR /app
COPY Cargo.toml Cargo.lock ./
RUN mkdir src && echo "fn main() {}" > src/main.rs
RUN cargo build --release || true
COPY . .
RUN cargo build --release
FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y ca-certificates tini wget && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=builder /app/target/release/security-rust /app/security-rust
RUN groupadd -r appgroup && useradd -r -g appgroup -u 1001 appuser
USER appuser
EXPOSE 8082
ENV PORT=8082
ENV APP_ENV=production
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 CMD wget --no-verbose --tries=1 --spider http://localhost:8082/health/live || exit 1
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["/app/security-rust"]
