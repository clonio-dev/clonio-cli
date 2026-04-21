# syntax=docker/dockerfile:1.7
FROM alpine:3

# ca-certificates — Guzzle-based audit adapters (S3, webhook, ntfy) need a CA bundle.
# tzdata — correct timestamps in audit logs and cloning dumps when TZ is set.
RUN apk add --no-cache ca-certificates tzdata

# CI stages the binaries as:
#   bin/clonio-linux-amd64  (from the x86_64 artifact)
#   bin/clonio-linux-arm64  (from the aarch64 artifact)
# buildx sets TARGETARCH to "amd64" or "arm64" per platform.
ARG TARGETARCH
COPY bin/clonio-linux-${TARGETARCH} /usr/local/bin/clonio
RUN chmod +x /usr/local/bin/clonio

WORKDIR /workspace
ENTRYPOINT ["/usr/local/bin/clonio"]
