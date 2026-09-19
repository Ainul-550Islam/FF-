FROM golang:1.22-alpine AS builder
WORKDIR /app
RUN apk add --no-cache git ca-certificates
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-w -s" -o /app/payment-gateway ./cmd/server
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-w -s" -o /app/migrator ./cmd/migrate
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-w -s" -o /app/worker ./cmd/worker
FROM alpine:3.19
RUN apk --no-cache add ca-certificates wget tini
WORKDIR /app
COPY --from=builder /app/payment-gateway /app/migrator /app/worker ./
COPY --from=builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
RUN addgroup -S appgroup && adduser -S appgroup appuser -G appgroup
USER appuser
EXPOSE 8081
ENV PORT=8081
ENV APP_ENV=production
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 CMD wget --no-verbose --tries=1 --spider http://localhost:8081/health/live || exit 1
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["/app/payment-gateway"]
